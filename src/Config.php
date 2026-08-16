<?php

declare(strict_types=1);

namespace Sixty;

/**
 * Configuration, under both names.
 *
 * The product is called sixty, so its variables are `SIXTY_*`. They used to be
 * `DRIFT_*`, and that name is not ours to retire: it is set in other people's
 * deployments — a Dockerfile, a Fly secret, a CI job someone configured once
 * and has not thought about since. Renaming without reading the old name would
 * not produce an error anybody could act on. It would produce an agent that
 * starts cleanly, finds no key, and reports nothing.
 *
 * `getenv` and `$_ENV` are both consulted, because php-fpm pools pass
 * environment through `env[...]` directives into `$_ENV` while a CLI worker
 * gets it from the process — and a config that works in the web process and not
 * in the queue worker is a config that reports half an application.
 */
final class Config
{
    public string $apiKey;
    public string $endpoint;
    public string $service;
    public string $environment;
    public string $release;
    public string $repoUrl;
    public float $flushInterval;
    public float $sampleRate;
    public float $slowTraceMs;
    public bool $capturePlans;
    public bool $debug;
    /** @var string[] */
    public array $ignorePaths;
    /** @var callable(string): void */
    public $onWarn;

    /**
     * Most PaaS providers set one of these without the user doing anything, and
     * release attribution is what makes deploy-anchored detection possible — so
     * the agent tries hard to find one before giving up. Without a release, two
     * deploys are one undifferentiated stream and nothing can be compared.
     */
    private const RELEASE_VARS = [
        'HEROKU_SLUG_COMMIT', 'RENDER_GIT_COMMIT', 'RAILWAY_GIT_COMMIT_SHA',
        'GITHUB_SHA', 'FLY_MACHINE_VERSION', 'SOURCE_VERSION', 'VERCEL_GIT_COMMIT_SHA',
    ];

    /** @param array<string, mixed> $options */
    public function __construct(array $options = [])
    {
        $this->apiKey = (string) ($options['api_key'] ?? self::env('API_KEY') ?? '');
        $this->endpoint = (string) ($options['endpoint'] ?? self::env('ENDPOINT') ?? 'http://localhost:4319');
        $this->service = (string) ($options['service'] ?? self::env('SERVICE') ?? 'unknown-service');
        $this->environment = (string) ($options['environment'] ?? self::env('ENV') ?? 'production');
        $this->release = (string) ($options['release'] ?? self::env('RELEASE') ?? self::detectRelease() ?? '');
        $this->repoUrl = (string) ($options['repo_url'] ?? self::env('REPO_URL') ?? self::githubRepo() ?? '');
        // Seconds in code, milliseconds in the environment — because
        // `SIXTY_FLUSH_MS` is the name every other agent in this repository
        // reads, and a PHP app and a Node app sharing a compose file must not
        // need two spellings of the same knob.
        $this->flushInterval = (float) ($options['flush_interval'] ?? (self::envNumber('FLUSH_MS', 15000) / 1000));
        $this->sampleRate = (float) ($options['sample_rate'] ?? self::envNumber('SAMPLE_RATE', 0.05));
        $this->slowTraceMs = (float) ($options['slow_trace_ms'] ?? self::envNumber('SLOW_TRACE_MS', 1000));
        $this->capturePlans = (bool) ($options['capture_plans'] ?? (self::env('CAPTURE_PLANS') !== '0'));
        $this->debug = (bool) ($options['debug'] ?? (self::env('DEBUG') === '1'));
        // Health checks and asset requests are noise: they are not the
        // application, and left in they dominate the operation list of every
        // service behind a load balancer.
        $this->ignorePaths = $options['ignore_paths'] ?? [
            '#\A/(_profiler|_wdt)#', '#\A/build/#', '#\A/assets/#', '#\A/health#', '#\A/up\z#', '#favicon\.ico\z#',
        ];
        $this->onWarn = $options['on_warn'] ?? static function (string $message): void {
            error_log($message);
        };
    }

    public function isActive(): bool
    {
        return $this->apiKey !== '';
    }

    public function ignores(string $path): bool
    {
        foreach ($this->ignorePaths as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Emptiness is checked per name rather than after choosing one. `SIXTY_X=`
     * in a compose file is someone who added the variable and has not filled it
     * in yet — if that shadowed a `DRIFT_X` that is actually set, adding the new
     * name would break a working deployment.
     */
    public static function env(string $name): ?string
    {
        foreach (["SIXTY_{$name}", "DRIFT_{$name}"] as $key) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function envNumber(string $name, float $fallback): float
    {
        $raw = self::env($name);

        return $raw !== null && is_numeric($raw) ? (float) $raw : $fallback;
    }

    private static function detectRelease(): ?string
    {
        foreach (self::RELEASE_VARS as $key) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function githubRepo(): ?string
    {
        $repo = $_ENV['GITHUB_REPOSITORY'] ?? getenv('GITHUB_REPOSITORY');

        return is_string($repo) && $repo !== '' ? "https://github.com/{$repo}" : null;
    }
}
