<?php

declare(strict_types=1);

namespace Sixty\Tests;

use PHPUnit\Framework\TestCase;
use Sixty\Buffer;
use Sixty\Sixty;
use Sixty\Span;
use Sixty\Tracer;

/**
 * The two promises this package makes to the application it is installed in:
 *
 *   1. It does not make the app slower in any way a user could notice.
 *   2. If the collector is down — or the agent itself is broken — the site
 *      behaves exactly as it would without the package.
 *
 * Both are properties of the code rather than of the intention behind it, so
 * they are asserted here.
 */
final class SafetyTest extends TestCase
{
    /** @var string[] */
    private array $warnings = [];

    protected function setUp(): void
    {
        $this->warnings = [];
        Sixty::reset();
        Buffer::clear();
        Sixty::init([
            'api_key' => 'sixty_sk_test',
            'endpoint' => 'http://127.0.0.1:1',
            'flush_interval' => 3600,
            'sample_rate' => 0,
            'on_warn' => function (string $message): void {
                $this->warnings[] = $message;
            },
        ]);
    }

    protected function tearDown(): void
    {
        Sixty::reset();
        Buffer::clear();
    }

    /**
     * The request path must never open a socket to the collector. Reporting
     * happens at shutdown, after the response is already sent — if that were
     * not true, a slow collector would become slow requests, which is the
     * failure mode every APM agent is remembered for.
     */
    public function testNothingIsSentDuringARequest(): void
    {
        for ($i = 0; $i < 20; $i++) {
            Sixty::trace('Orders#index', static fn (): null => null);
        }

        $this->assertSame(0, Sixty::exporter()?->failures(), 'the agent contacted the collector mid-request');

        Sixty::flush(force: true);

        $this->assertGreaterThanOrEqual(1, Sixty::exporter()?->failures());
        $this->assertStringContainsString('ingest unreachable', implode("\n", $this->warnings));
    }

    /**
     * An agent bug is a bug in the agent, not in the application. The sink is
     * every path from a finished span into the rollup, so a throw there is the
     * most plausible shape an internal error takes.
     */
    public function testABrokenAgentDoesNotBreakTheApplication(): void
    {
        Tracer::setSink(static function (Span $span): void {
            throw new \RuntimeException('the agent is on fire');
        });

        $result = Sixty::trace('Orders#index', static fn (): string => 'the response');

        $this->assertSame('the response', $result);
    }

    public function testAnApplicationErrorIsRecordedAndStillThrown(): void
    {
        $this->expectException(\DomainException::class);

        try {
            Sixty::trace('Orders#index', static function (): void {
                throw new \DomainException('disk gone');
            });
        } finally {
            $payload = Sixty::aggregator()?->drain();
            $this->assertSame(1, $payload['operations'][0]['errors']);
        }
    }

    /**
     * Memory is bounded by cardinality, never by traffic or by how long the
     * collector has been down.
     */
    public function testMemoryDoesNotGrowWithTraffic(): void
    {
        for ($i = 0; $i < 5000; $i++) {
            Sixty::trace('Orders#index', static fn (): null => null);
        }

        $payload = Sixty::aggregator()?->drain();

        $this->assertCount(1, $payload['operations'], 'five thousand calls are one operation');
        $this->assertSame(5000, $payload['operations'][0]['count']);
    }

    public function testMongoInstrumentationLoadsWithoutTheExtension(): void
    {
        $source = realpath(__DIR__ . '/../src/Instrument/Mongo.php');
        $code = sprintf(
            'require %s; exit(class_exists("Sixty\\Instrument\\Mongo") ? 0 : 9);',
            var_export($source, true),
        );
        $command = escapeshellarg(PHP_BINARY) . ' -n -r ' . escapeshellarg($code);
        exec($command, $output, $status);
        $this->assertSame(0, $status, 'the optional Mongo adapter caused a fatal error without ext-mongodb');
    }

    /**
     * A number rather than a promise. The threshold is deliberately loose —
     * this is a regression guard against something pathological (a backtrace
     * per call, a serialization per span), not a benchmark.
     */
    public function testTracingOverheadPerCallIsMicroseconds(): void
    {
        $iterations = 20000;
        $work = static fn (): int => 1 + 1;

        Sixty::reset();
        $bare = self::measure($iterations, $work);

        Sixty::init(['api_key' => 'k', 'endpoint' => 'http://127.0.0.1:1', 'flush_interval' => 3600]);
        $traced = self::measure($iterations, static fn () => Sixty::trace('Bench#call', $work));

        $overheadUs = (($traced - $bare) / $iterations) * 1e6;
        printf("\n  tracing overhead: %.1fµs per call\n", $overheadUs);

        $this->assertLessThan(100, $overheadUs, 'a traced call should cost microseconds, not milliseconds');
    }

    private static function measure(int $iterations, callable $work): float
    {
        $started = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $work();
        }

        return (hrtime(true) - $started) / 1e9;
    }
}
