<?php

declare(strict_types=1);

namespace Sixty;

/**
 * Transport. Ships aggregate windows and exemplar traces to the collector.
 *
 * Rules this obeys, because an observability agent that harms the host is worse
 * than no agent at all:
 *   - never delay the response (the flush runs after
 *     `fastcgi_finish_request()`; see Sixty::shutdown)
 *   - never grow without bound (the exemplar queue is capped and drops oldest)
 *   - never throw into user code (every failure is swallowed and counted)
 *   - never hold a worker hostage to a slow collector (short timeouts, and a
 *     backoff recorded in shared memory so one outage does not cost every
 *     request in the pool a timeout each)
 */
final class Exporter
{
    public const MAX_QUEUED_EXEMPLARS = 200;
    public const TIMEOUT_SECONDS = 5;

    /** @var array<int, array<string, mixed>> */
    private array $exemplars = [];
    private int $failures = 0;

    /** @var callable(string): void */
    private $onWarn;

    /** @var array{service: string, environment: string, release: string, repoUrl: string} */
    private array $resource;

    public function __construct(
        private string $endpoint,
        private ?string $apiKey,
        string $service,
        string $environment,
        string $release,
        string $repoUrl = '',
        private string $path = '/v1/ingest',
        ?callable $onWarn = null,
    ) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->resource = [
            'service' => $service,
            'environment' => $environment,
            'release' => $release,
            'repoUrl' => $repoUrl,
        ];
        $this->onWarn = $onWarn ?? static function (string $message): void {
        };
    }

    /** @param array<string, mixed> $trace */
    public function queueExemplar(array $trace): void
    {
        if (count($this->exemplars) >= self::MAX_QUEUED_EXEMPLARS) {
            array_shift($this->exemplars);
        }
        $this->exemplars[] = $trace;
    }

    /** @return array<int, array<string, mixed>> */
    public function takeExemplars(): array
    {
        $taken = $this->exemplars;
        $this->exemplars = [];

        return $taken;
    }

    public function failures(): int
    {
        return $this->failures;
    }

    /**
     * @param array<string, mixed>|null $metrics a drained window
     * @param array<int, array<string, mixed>> $exemplars
     */
    public function flush(?array $metrics, array $exemplars = [], ?int $timeout = null): void
    {
        if ($metrics === null && $exemplars === []) {
            return;
        }

        $body = json_encode([
            'resource' => $this->resource,
            'metrics' => $metrics,
            'exemplars' => $exemplars,
            'sentAt' => (int) round(microtime(true) * 1000),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if (!is_string($body)) {
            return;
        }

        $this->post($body, $timeout ?? self::TIMEOUT_SECONDS);
    }

    private function post(string $body, int $timeout): void
    {
        $headers = ['Content-Type: application/json'];
        // Omitted rather than sent empty when there is no key, so a
        // misconfiguration reads as "no credential" at the collector rather
        // than as an empty one it has to decide what to do with.
        if ($this->apiKey !== null && $this->apiKey !== '') {
            $headers[] = "Authorization: Bearer {$this->apiKey}";
        }

        $handle = curl_init($this->endpoint . $this->path);
        if ($handle === false) {
            return;
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(2, $timeout),
            // A redirect from a telemetry endpoint is not something to follow
            // blindly with a bearer token attached.
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($response === false || $status === 0) {
            $this->warn("ingest unreachable: {$error}");

            return;
        }
        if ($status >= 300) {
            $text = is_string($response) ? substr($response, 0, 200) : '';
            $this->warn("ingest rejected payload: {$status} {$text}");

            return;
        }

        $this->failures = 0;
    }

    private function warn(string $message): void
    {
        $this->failures++;
        ($this->onWarn)("sixty: {$message}");
    }
}
