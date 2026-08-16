<?php

declare(strict_types=1);

namespace Sixty\Tests;

use PHPUnit\Framework\TestCase;
use Sixty\Instrument\Pdo;
use Sixty\Instrument\TracedPdo;
use Sixty\Sixty;

/**
 * PDO against real servers.
 *
 * A mock cannot check any of what matters here: that `rowCount()` means what
 * this agent claims it means on each driver, that the statement class does not
 * displace somebody else's, and that a MySQL statement is lexed by MySQL's
 * rules — the one class of bug that would transmit a customer's data.
 */
final class PdoTest extends TestCase
{
    use Databases;

    protected function setUp(): void
    {
        Sixty::reset();
        Sixty::init([
            'api_key' => 'k',
            'endpoint' => 'http://127.0.0.1:1',
            'flush_interval' => 3600,
            'sample_rate' => 0,
            'capture_plans' => false,
            'on_warn' => static fn (string $m): null => null,
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

        return $payload === null ? [] : array_column($payload['operations'], null, 'key');
    }

    private function seedPg(\PDO $pdo): void
    {
        $pdo->exec('drop table if exists sixty_test_orders');
        $pdo->exec('create table sixty_test_orders (
            id serial primary key, user_id int not null, email text, total int not null default 0)');
        $pdo->exec("insert into sixty_test_orders (user_id, email, total) values
            (1,'a@example.com',10),(1,'b@example.com',20),(1,'c@example.com',30),
            (2,'d@example.com',40),(2,'e@example.com',50),(3,'f@example.com',60)");
        Sixty::aggregator()?->drain();
        \Sixty\Stack::reset();
    }

    public function testASelectReportsTheRowsItReturned(): void
    {
        $pdo = $this->connectPg() ?? $this->markTestSkipped("no Postgres: {$this->connectError}");
        Pdo::instrument($pdo);
        $this->seedPg($pdo);

        Sixty::trace('OrdersReport#call', static function () use ($pdo): void {
            $statement = $pdo->prepare('select * from sixty_test_orders where user_id = ?');
            $statement->execute([1]);
            $statement->fetchAll();
        });

        $operation = $this->drain()["db\x01select * from sixty_test_orders where user_id = ?"];

        $this->assertSame(1, $operation['count']);
        $this->assertSame(3, $operation['rowsSum']);
        $this->assertSame('select:sixty_test_orders', $operation['name']);
    }

    public function testAWriteReportsTheRowsItChanged(): void
    {
        $pdo = $this->connectPg() ?? $this->markTestSkipped("no Postgres: {$this->connectError}");
        Pdo::instrument($pdo);
        $this->seedPg($pdo);

        Sixty::trace('OrdersWriter#call', static function () use ($pdo): void {
            $statement = $pdo->prepare('update sixty_test_orders set total = total + 1 where user_id = ?');
            $statement->execute([1]);
        });

        $operation = array_values(array_filter($this->drain(), static fn ($op): bool => $op['kind'] === 'db'))[0];

        $this->assertSame(3, $operation['rowsSum']);
    }

    public function testQueriesAreCreditedToTheMethodThatIssuedThem(): void
    {
        $pdo = $this->connectPg() ?? $this->markTestSkipped("no Postgres: {$this->connectError}");
        Pdo::instrument($pdo);
        $this->seedPg($pdo);

        Sixty::trace('OrdersController#index', static function () use ($pdo): void {
            for ($i = 1; $i <= 3; $i++) {
                $statement = $pdo->prepare('select * from sixty_test_orders where user_id = ?');
                $statement->execute([$i]);
            }
        });

        $controller = $this->drain()["function\x01OrdersController#index"];

        $this->assertSame(3, $controller['dbCallsSum']);
        $this->assertSame(6, $controller['rowsSum']); // 3 + 2 + 1
    }

    public function testAFailingQueryIsRecordedAndStillThrows(): void
    {
        $pdo = $this->connectPg() ?? $this->markTestSkipped("no Postgres: {$this->connectError}");
        Pdo::instrument($pdo);
        $this->seedPg($pdo);

        try {
            Sixty::trace('Broken#call', static function () use ($pdo): void {
                $pdo->prepare('select * from sixty_no_such_table')->execute();
            });
            $this->fail('the driver error should have propagated');
        } catch (\PDOException) {
            // deliberate
        }

        $operation = array_values(array_filter($this->drain(), static fn ($op): bool => $op['kind'] === 'db'))[0];
        $this->assertSame(1, $operation['errors']);
    }

    public function testNoLiteralFromARealQueryReachesThePayload(): void
    {
        $pdo = $this->connectPg() ?? $this->markTestSkipped("no Postgres: {$this->connectError}");
        Pdo::instrument($pdo);
        $this->seedPg($pdo);

        Sixty::trace('Literal#call', static function () use ($pdo): void {
            $pdo->prepare("select * from sixty_test_orders where email = 'alice@example.com'")->execute();
        });

        $this->assertStringNotContainsString('alice', json_encode(Sixty::aggregator()?->drain()));
    }

    /**
     * A profiler or a debug bar may already own the statement class. Taking it
     * would break them, and a missing measurement is better than a broken
     * application.
     */
    public function testAnExistingStatementClassIsLeftAlone(): void
    {
        $pdo = $this->connectPg() ?? $this->markTestSkipped("no Postgres: {$this->connectError}");
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [SomebodyElsesStatement::class]);

        $this->assertFalse(Pdo::instrument($pdo));
    }

    public function testMysqlIsLexedByMysqlRules(): void
    {
        $pdo = $this->connectMysql() ?? $this->markTestSkipped("no MySQL: {$this->connectError}");
        Pdo::instrument($pdo);
        $pdo->exec('drop table if exists sixty_test_orders');
        $pdo->exec('create table sixty_test_orders (
            id int auto_increment primary key, user_id int not null, email varchar(255))');
        $pdo->exec("insert into sixty_test_orders (user_id, email) values
            (1,'a@example.com'),(1,'b@example.com'),(2,'c@example.com')");
        Sixty::aggregator()?->drain();
        \Sixty\Stack::reset();

        Sixty::trace('Quoted#call', static function () use ($pdo): void {
            // Backticks are identifiers and must survive; the double-quoted run
            // of bytes is a *value* in MySQL and must not.
            $statement = $pdo->prepare(
                'select `id`, `email` from `sixty_test_orders` where `email` = "alice@example.com"'
            );
            $statement->execute();
        });

        $payload = json_encode(Sixty::aggregator()?->drain());
        $this->assertStringNotContainsString('alice', (string) $payload);
        $this->assertStringContainsString('`sixty_test_orders`', (string) $payload);
    }

    public function testMysqlReportsRowsAndCreditsThem(): void
    {
        $pdo = $this->connectMysql() ?? $this->markTestSkipped("no MySQL: {$this->connectError}");
        Pdo::instrument($pdo);
        Sixty::aggregator()?->drain();

        Sixty::trace('Orders#index', static function () use ($pdo): void {
            $statement = $pdo->prepare('select * from sixty_test_orders where user_id = ?');
            $statement->execute([1]);
            $statement->fetchAll();
        });

        $controller = $this->drain()["function\x01Orders#index"];

        $this->assertSame(1, $controller['dbCallsSum']);
        $this->assertSame(2, $controller['rowsSum']);
    }

    /** `query()` and `exec()` never reach the statement class; TracedPdo covers them. */
    public function testTracedPdoMeasuresQueryAndExec(): void
    {
        $this->connectPg() ?? $this->markTestSkipped("no Postgres: {$this->connectError}");
        $pdo = new TracedPdo($this->pgDsn(), 'drift', 'drift');
        $this->seedPg($pdo);

        Sixty::trace('Direct#call', static function () use ($pdo): void {
            $pdo->query('select * from sixty_test_orders');
            $pdo->exec('update sixty_test_orders set total = total + 1 where user_id = 2');
        });

        $ops = $this->drain();

        $this->assertSame(6, $ops["db\x01select * from sixty_test_orders"]['rowsSum']);
        $this->assertSame(2, $ops["function\x01Direct#call"]['dbCallsSum']);
    }
}

/** Stands in for a profiler that got there first. */
final class SomebodyElsesStatement extends \PDOStatement
{
}
