<?php

declare(strict_types=1);

namespace Sixty\Tests;

use PHPUnit\Framework\TestCase;
use Sixty\Aggregator;
use Sixty\Sixty;
use Sixty\Sql;
use Sixty\Tracer;

/**
 * Attribution, and the payload it turns into.
 *
 * These are the numbers every finding is made of: if a query is credited to the
 * wrong parent, an N+1 is reported against the wrong method and the finding
 * sends somebody to the wrong file.
 */
final class AgentTest extends TestCase
{
    /** @var string[] */
    private array $warnings = [];

    protected function setUp(): void
    {
        $this->warnings = [];
        Sixty::reset();
        // 127.0.0.1:1 is closed on every machine, so this agent is configured
        // against a collector that cannot possibly answer.
        Sixty::init([
            'api_key' => 'sixty_sk_test',
            'endpoint' => 'http://127.0.0.1:1',
            'service' => 'test-service',
            'environment' => 'test',
            'release' => 'test-release',
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
    }

    /** @return array<string, array<string, mixed>> */
    private function drain(): array
    {
        $payload = Sixty::aggregator()?->drain();
        if ($payload === null) {
            return [];
        }

        return array_column($payload['operations'], null, 'key');
    }

    private function query(string $sql, int $rows): void
    {
        $normalized = Sql::normalize($sql);
        Tracer::record(Tracer::KIND_DB, Sql::operationName($normalized), 1.0, [
            'normalizedSql' => $normalized,
            'rows' => $rows,
        ]);
    }

    public function testQueriesAreCreditedToEveryAncestor(): void
    {
        Sixty::trace('OrdersController#index', function (): void {
            Sixty::trace('OrdersQuery#recent', function (): void {
                for ($i = 0; $i < 3; $i++) {
                    $this->query('select * from orders where user_id = $1', 10);
                }
            });
        });

        $ops = $this->drain();

        $this->assertSame(3, $ops["function\x01OrdersController#index"]['dbCallsSum']);
        $this->assertSame(3, $ops["function\x01OrdersQuery#recent"]['dbCallsSum']);
        // Rows are the signal a slow-query view cannot show you: three calls
        // each returning ten rows is thirty rows of work for the request.
        $this->assertSame(30, $ops["function\x01OrdersController#index"]['rowsSum']);
    }

    public function testDbOperationsAreIdentifiedByStatementNotByLabel(): void
    {
        $this->query('select * from orders where id = $1', 1);
        $this->query('select * from orders where id = $2', 1);

        $ops = $this->drain();

        $this->assertCount(1, $ops, 'two spellings of one parameterised statement are one operation');
        $this->assertSame(2, reset($ops)['count']);
        $this->assertSame('select:orders', reset($ops)['name']);
    }

    public function testEdgesRecordWhichChildAParentCalled(): void
    {
        Sixty::trace('Orders#show', function (): void {
            $this->query('select * from order_items where order_id = $1', 5);
            $this->query('select * from order_items where order_id = $1', 5);
        });

        $payload = Sixty::aggregator()?->drain();
        $edge = $payload['edges'][0];

        $this->assertSame("function\x01Orders#show", $edge['parentKey']);
        $this->assertSame("db\x01select * from order_items where order_id = ?", $edge['childKey']);
        $this->assertSame(2, $edge['count']);
        $this->assertSame(10, $edge['rowsSum']);
    }

    public function testSelfTimeExcludesChildren(): void
    {
        Sixty::trace('Slow#call', function (): void {
            Tracer::record(Tracer::KIND_DB, 'select:t', 50.0, ['normalizedSql' => 'select ?']);
            usleep(2000);
        });

        $op = $this->drain()["function\x01Slow#call"];
        $duration = \Sixty\Sketch::fromBinary((string) base64_decode($op['durationSketch'], true));
        $self = \Sixty\Sketch::fromBinary((string) base64_decode($op['selfSketch'], true));

        $this->assertGreaterThan($self->sum(), $duration->sum(), 'a parent that called something is not all self time');
    }

    public function testAThrownErrorIsRecordedAndRethrown(): void
    {
        try {
            Sixty::trace('Failing#call', static function (): void {
                throw new \InvalidArgumentException('nope');
            });
            $this->fail('the exception should have propagated');
        } catch (\InvalidArgumentException) {
            // deliberate
        }

        $op = $this->drain()["function\x01Failing#call"];

        $this->assertSame(1, $op['errors']);
        $this->assertSame(
            [['message' => 'InvalidArgumentException: nope', 'count' => 1]],
            $op['errorSamples'],
        );
        $this->assertNull(Tracer::current(), 'a thrown span must not leak into the next request');
    }

    public function testCardinalityIsCappedAndSaysSo(): void
    {
        for ($i = 0; $i < Aggregator::MAX_OPERATIONS + 100; $i++) {
            Sixty::trace("Generated#method{$i}", static fn (): null => null);
        }

        $ops = $this->drain();

        $this->assertLessThanOrEqual(Aggregator::MAX_OPERATIONS + 1, count($ops));
        $this->assertArrayHasKey('__overflow__', $ops);
        $this->assertStringContainsString('cardinality cap', implode("\n", $this->warnings));
    }

    public function testEveryFieldTheCollectorReadsIsPresent(): void
    {
        Sixty::trace('Orders#index', function (): void {
            $this->query('select * from orders where id = $1', 3);
        });

        $payload = Sixty::aggregator()?->drain();
        $operation = $payload['operations'][0];

        foreach (['key', 'kind', 'name', 'count', 'errors', 'durationSketch', 'selfSketch',
            'rowsSum', 'dbCallsSum', 'dbCallsMax', 'roundTripsSum'] as $field) {
            $this->assertArrayHasKey($field, $operation);
        }
        $this->assertGreaterThanOrEqual($payload['windowStart'], $payload['windowEnd']);
    }

    public function testDrainingAnEmptyWindowSendsNothing(): void
    {
        $this->assertNull(Sixty::aggregator()?->drain());
    }
}
