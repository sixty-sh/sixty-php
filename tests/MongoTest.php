<?php

declare(strict_types=1);

namespace Sixty\Tests;

use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;
use PHPUnit\Framework\TestCase;
use Sixty\Instrument\Mongo;
use Sixty\Sixty;

/**
 * MongoDB, against a real mongod.
 *
 * Two claims are checked here that nothing else can check: that the driver's
 * command events really are published inside the call that issued them — which
 * is the entire basis for parenting a query to the method that ran it — and
 * that the document count means the same thing it means for SQL.
 */
final class MongoTest extends TestCase
{
    use Databases;

    private Manager $manager;
    private string $database = 'sixty_test';

    protected function setUp(): void
    {
        if (!class_exists(Manager::class)) {
            $this->markTestSkipped('ext-mongodb is not installed');
        }

        try {
            $this->manager = new Manager($this->mongoUri(), ['serverSelectionTimeoutMS' => 2000]);
            $this->manager->executeCommand($this->database, new Command(['ping' => 1]));
        } catch (\Throwable $e) {
            $this->markTestSkipped("no MongoDB: {$e->getMessage()}");
        }

        Sixty::reset();
        Mongo::reset();
        Sixty::init([
            'api_key' => 'k',
            'endpoint' => 'http://127.0.0.1:1',
            'flush_interval' => 3600,
            'sample_rate' => 0,
            'on_warn' => static fn (string $m): null => null,
        ]);
        Mongo::install();
        $this->seed();
    }

    protected function tearDown(): void
    {
        Mongo::reset();
        Sixty::reset();
    }

    /** @return array<string, array<string, mixed>> */
    private function drain(): array
    {
        $payload = Sixty::aggregator()?->drain();

        return $payload === null ? [] : array_column($payload['operations'], null, 'key');
    }

    private function seed(): void
    {
        $this->manager->executeCommand($this->database, new Command(['drop' => 'orders']));
        $bulk = new BulkWrite();
        foreach ([[1, 'a@example.com'], [1, 'b@example.com'], [1, 'c@example.com'],
            [2, 'd@example.com'], [2, 'e@example.com'], [3, 'f@example.com'],
            [4, 'g@example.com'], [5, 'h@example.com']] as [$user, $email]) {
            $bulk->insert(['user_id' => $user, 'email' => $email, 'total' => $user * 10]);
        }
        $this->manager->executeBulkWrite("{$this->database}.orders", $bulk);
        Sixty::aggregator()?->drain();
        \Sixty\Stack::reset();
    }

    /** @param array<string, mixed> $filter */
    private function find(array $filter, array $options = []): array
    {
        return $this->manager
            ->executeQuery("{$this->database}.orders", new Query($filter, $options))
            ->toArray();
    }

    public function testAFindReportsTheDocumentsItReturned(): void
    {
        Sixty::trace('OrdersQuery#forUser', function (): void {
            $this->find(['user_id' => 1]);
        });

        $operation = array_values(array_filter($this->drain(), static fn ($op): bool => $op['kind'] === 'db'))[0];

        $this->assertSame('find:orders', $operation['name']);
        $this->assertSame(3, $operation['rowsSum']);
    }

    /**
     * The attribution claim. If the extension published its events anywhere
     * other than inside the call, this would be zero.
     */
    public function testQueriesAreCreditedToTheMethodThatIssuedThem(): void
    {
        Sixty::trace('OrdersController#index', function (): void {
            foreach ([1, 2, 3] as $user) {
                $this->find(['user_id' => $user]);
            }
        });

        $controller = $this->drain()["function\x01OrdersController#index"];

        $this->assertSame(3, $controller['dbCallsSum']);
        $this->assertSame(6, $controller['rowsSum']); // 3 + 2 + 1
    }

    /**
     * Identity is the shape of the query, never its values — so the same query
     * for two users is one operation, and a filter on a different field is
     * another. This is what SQL normalization does for statements.
     */
    public function testIdentityIsTheShapeOfTheFilter(): void
    {
        Sixty::trace('Shapes#call', function (): void {
            $this->find(['user_id' => 1]);
            $this->find(['user_id' => 2]);
            $this->find(['email' => 'alice@example.com']);
        });

        $keys = array_values(array_filter(
            array_keys($this->drain()),
            static fn (string $key): bool => str_starts_with($key, "db\x01"),
        ));

        $this->assertCount(2, $keys, 'two filters, two operations, however many values');
        $this->assertContains("db\x01find orders {filter{user_id}}", $keys);
        $this->assertContains("db\x01find orders {filter{email}}", $keys);
    }

    public function testNoValueFromAQueryReachesThePayload(): void
    {
        Sixty::trace('Literal#call', function (): void {
            $this->find(['email' => 'alice@example.com']);
            $bulk = new BulkWrite();
            $bulk->insert(['user_id' => 9, 'email' => 'bob@example.com']);
            $this->manager->executeBulkWrite("{$this->database}.orders", $bulk);
        });

        $payload = (string) json_encode(Sixty::aggregator()?->drain());

        $this->assertStringNotContainsString('alice', $payload);
        $this->assertStringNotContainsString('bob', $payload);
    }

    /**
     * An aggregation pipeline is ordered structure: every stage counts, and in
     * order. Collapsing it would hide the `$lookup` that turned one query into
     * the N+1 this product exists to report.
     */
    public function testAPipelineKeepsItsStages(): void
    {
        Sixty::trace('Report#call', function (): void {
            $this->manager->executeCommand($this->database, new Command([
                'aggregate' => 'orders',
                'pipeline' => [
                    ['$match' => ['user_id' => 1]],
                    ['$group' => ['_id' => '$user_id', 'n' => ['$sum' => 1]]],
                ],
                'cursor' => new \stdClass(),
            ]))->toArray();
        });

        $keys = array_filter(array_keys($this->drain()), static fn (string $k): bool => str_contains($k, 'aggregate'));

        $this->assertContains(
            "db\x01aggregate orders [\$match{user_id}][\$group{_id,n{\$sum}}]",
            array_values($keys),
        );
    }

    /**
     * Counting a million-document collection did not return a million
     * documents. Reporting the answer as the row count would make every growing
     * collection look like the regression this product exists to report.
     */
    public function testACountReportsOneDocumentNotTheCount(): void
    {
        Sixty::trace('Counter#call', function (): void {
            $this->manager->executeCommand($this->database, new Command(['count' => 'orders']))->toArray();
        });

        $operation = array_values(array_filter($this->drain(), static fn ($op): bool => $op['kind'] === 'db'))[0];

        $this->assertSame(1, $operation['rowsSum']);
    }

    /**
     * A cursor is one operation however many batches it took, and the batches
     * are counted as round trips rather than as calls. Recording each getMore
     * separately would tell the reader their method makes N database calls —
     * the signature of an N+1 their code does not contain.
     */
    public function testACursorIsOneOperationWithItsRoundTripsCounted(): void
    {
        Sixty::trace('Scan#call', function (): void {
            $this->find([], ['batchSize' => 2]);
        });

        $ops = $this->drain();
        $caller = $ops["function\x01Scan#call"];
        $find = array_values(array_filter($ops, static fn ($op): bool => $op['name'] === 'find:orders'))[0];

        $this->assertSame(
            [],
            array_filter($ops, static fn ($op): bool => $op['name'] === 'getMore:orders'),
            'a batch is not an operation of its own',
        );
        $this->assertSame(1, $caller['dbCallsSum'], 'one query is one database call');
        $this->assertSame(8, $caller['rowsSum'], 'every document, however many batches it took');
        $this->assertGreaterThanOrEqual(4, $find['roundTripsSum'], 'eight documents, two at a time');
    }

    public function testASingleBatchReadReportsOneRoundTrip(): void
    {
        Sixty::trace('Small#call', function (): void {
            $this->find(['user_id' => 1]);
        });

        $find = array_values(array_filter($this->drain(), static fn ($op): bool => $op['name'] === 'find:orders'))[0];

        $this->assertSame(1, $find['roundTripsSum']);
    }

    /**
     * Batch size is how the results are fetched, not what was asked for — and
     * if it entered the identity, setting it would re-identify the operation at
     * the exact moment the round-trips signal had something to say about it.
     */
    public function testBatchSizeDoesNotChangeTheIdentity(): void
    {
        Sixty::trace('Batched#call', function (): void {
            $this->find(['user_id' => 1]);
            $this->find(['user_id' => 1], ['batchSize' => 2]);
        });

        $keys = array_values(array_filter(
            array_keys($this->drain()),
            static fn (string $key): bool => str_starts_with($key, "db\x01"),
        ));

        $this->assertSame(["db\x01find orders {filter{user_id}}"], $keys);
    }

    public function testAFailingCommandIsRecordedAndStillThrows(): void
    {
        try {
            Sixty::trace('Broken#call', function (): void {
                $this->manager->executeCommand($this->database, new Command(['nonsense' => 'orders']))->toArray();
            });
            $this->fail('the driver error should have propagated');
        } catch (\Throwable) {
            // deliberate
        }

        $failed = array_filter($this->drain(), static fn ($op): bool => $op['kind'] === 'db' && $op['errors'] > 0);

        $this->assertNotEmpty($failed);
    }

    public function testDriverHousekeepingIsNotAnOperation(): void
    {
        Sixty::trace('Ping#call', function (): void {
            $this->manager->executeCommand($this->database, new Command(['ping' => 1]));
        });

        foreach ($this->drain() as $op) {
            $this->assertStringNotContainsString('ping', (string) $op['name']);
        }
    }
}
