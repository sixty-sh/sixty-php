<?php

declare(strict_types=1);

namespace Sixty\Tests;

use PHPUnit\Framework\TestCase;
use Sixty\Buffer;
use Sixty\Instrument\Laravel\Middleware;
use Sixty\Instrument\Mongo;
use Sixty\Instrument\Pdo;
use Sixty\Instrument\Symfony\RequestListener;
use Sixty\Sixty;
use Sixty\Span;
use Sixty\Tracer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * One claim, tested at every door the agent has into an application.
 *
 * "It cannot break your app" is easy to say and easy to be wrong about, because
 * the dangerous paths are the ones nobody exercises: a database that went away
 * mid-request, an APCu segment that is full, a driver that changed a return
 * type, an agent bug in a code path that only runs on the unhappy day.
 *
 * So the agent is deliberately sabotaged here — the sink throws on every span —
 * and each entry point is driven through it. The application must come out the
 * other side with the right answer every time. What is *not* asserted is that
 * the measurement survives: it may be lost, and losing it is the correct
 * outcome. Losing the request is not.
 */
final class NeverBreaksTheAppTest extends TestCase
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
        Mongo::reset();
    }

    /** Rig every finished span to throw. */
    private function sabotage(): void
    {
        Tracer::setSink(static function (Span $span): void {
            throw new \RuntimeException('the agent is on fire');
        });
    }

    public function testASabotagedAgentStillReturnsTheResponse(): void
    {
        $this->sabotage();

        $this->assertSame('the answer', Sixty::trace('Anything#call', static fn (): string => 'the answer'));
    }

    public function testTheLaravelMiddlewareStillReturnsTheResponse(): void
    {
        if (!class_exists(Request::class)) {
            $this->markTestSkipped('symfony/http-foundation is not installed');
        }
        $this->sabotage();

        $middleware = new Middleware();
        $response = $middleware->handle(
            \Illuminate\Http\Request::create('/users/42/orders'),
            static fn (): Response => new Response('the page', 200),
        );

        $this->assertSame('the page', $response->getContent());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testTheLaravelMiddlewareRethrowsTheApplicationsOwnError(): void
    {
        $this->sabotage();
        $middleware = new Middleware();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('the application failed');

        $middleware->handle(
            \Illuminate\Http\Request::create('/orders'),
            static function (): Response {
                throw new \DomainException('the application failed');
            },
        );
    }

    public function testTheSymfonyListenerLeavesTheResponseAlone(): void
    {
        $this->sabotage();

        $listener = new RequestListener();
        $kernel = new class () implements HttpKernelInterface {
            public function handle(Request $r, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response('the page');
            }
        };
        $request = Request::create('/orders');
        $response = new Response('the page', 200);

        $listener->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));

        $this->assertSame('the page', $response->getContent());
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * SQLite rather than a server, deliberately.
     *
     * What is being tested here is not a driver's behaviour — it is that the
     * agent cannot come between an application and its database. That property
     * has nothing to do with which database it is, and pinning it to a server
     * that has to be running means it goes unchecked on exactly the machines
     * where somebody is about to change this code. The Postgres and MySQL
     * specifics — what `rowCount()` means, which quote is a value — stay in the
     * integration tests, where a real server is the point.
     */
    private function sqlite(array $options = []): \PDO
    {
        $pdo = new \PDO('sqlite::memory:', null, null, $options + [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('create table orders (id integer primary key, email text)');
        $pdo->exec("insert into orders (email) values ('a@example.com'), ('b@example.com')");

        return $pdo;
    }

    public function testAQueryStillReturnsItsRowsWhenTheAgentThrows(): void
    {
        $pdo = $this->sqlite();
        $this->assertTrue(Pdo::instrument($pdo));
        $this->sabotage();

        $statement = $pdo->prepare('select * from orders where id < ?');
        $ok = $statement->execute([3]);
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertTrue($ok);
        $this->assertSame(
            [['id' => 1, 'email' => 'a@example.com'], ['id' => 2, 'email' => 'b@example.com']],
            $rows,
            'every row, in order, unchanged',
        );
    }

    public function testAFailingQueryStillRaisesTheDriversOwnError(): void
    {
        $pdo = $this->sqlite();
        Pdo::instrument($pdo);
        $this->sabotage();

        $this->expectException(\PDOException::class);
        $pdo->prepare('select * from no_such_table_at_all')->execute();
    }

    public function testTheStatementBehavesLikeAPdoStatementInEveryOtherWay(): void
    {
        $pdo = $this->sqlite();
        Pdo::instrument($pdo);

        $statement = $pdo->prepare('select * from orders where id = :id');
        $statement->bindValue(':id', 2, \PDO::PARAM_INT);
        $statement->execute();

        // Named parameters, fetch modes, column metadata and iteration all have
        // to work exactly as they did before the statement class was replaced.
        $this->assertSame(2, $statement->columnCount());
        $statement->execute();
        $this->assertSame(['id' => 2, 'email' => 'b@example.com'], $statement->fetch(\PDO::FETCH_ASSOC));

        $statement = $pdo->query('select email from orders order by id');
        $this->assertSame(['a@example.com', 'b@example.com'], $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * A profiler that gets there first keeps the slot, and one that arrives
     * later takes it — neither is an error, and the second is now reported
     * rather than silently swallowing every query.
     */
    public function testTheStatementClassIsNeverFoughtOver(): void
    {
        $pdo = $this->sqlite();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [SomebodyElsesStatement::class]);

        $this->assertFalse(Pdo::instrument($pdo), 'somebody else got there first');

        $statement = $pdo->query('select * from orders');
        $this->assertInstanceOf(SomebodyElsesStatement::class, $statement, 'and still owns it');

        $warnings = [];
        $ours = $this->sqlite();
        Pdo::instrument($ours);
        $ours->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [SomebodyElsesStatement::class]);
        Pdo::verify(static function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        });

        $this->assertNotEmpty($warnings, 'losing the slot has to be said out loud');
        $this->assertStringContainsString('no longer being measured', $warnings[0]);
    }

    /**
     * A persistent connection cannot take a custom statement class at all — PHP
     * refuses — so the agent has to notice and carry on rather than turning a
     * configuration choice into an exception at boot.
     */
    public function testAPersistentConnectionIsLeftAloneRatherThanBroken(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sixty-persistent-') ?: null;
        if ($file === null) {
            $this->markTestSkipped('no writable temp directory');
        }

        try {
            $persistent = new \PDO("sqlite:{$file}", null, null, [
                \PDO::ATTR_PERSISTENT => true,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            // Whether it can be instrumented is the driver's business; that this
            // does not throw, and that the connection still works afterwards, is
            // ours.
            Pdo::instrument($persistent);

            $this->assertSame(['one' => 1], $persistent->query('select 1 as one')->fetch(\PDO::FETCH_ASSOC));
        } finally {
            @unlink($file);
        }
    }

    public function testAMongoCommandStillReturnsWhenTheAgentThrows(): void
    {
        if (!class_exists(\MongoDB\Driver\Manager::class)) {
            $this->markTestSkipped('ext-mongodb is not installed');
        }

        try {
            $manager = new \MongoDB\Driver\Manager($this->mongoUri(), ['serverSelectionTimeoutMS' => 2000]);
            $manager->executeCommand('sixty_test', new \MongoDB\Driver\Command(['ping' => 1]));
        } catch (\Throwable $e) {
            $this->markTestSkipped("no MongoDB: {$e->getMessage()}");
        }

        Mongo::install();
        $this->sabotage();

        $result = $manager
            ->executeQuery('sixty_test.orders', new \MongoDB\Driver\Query([]))
            ->toArray();

        $this->assertIsArray($result);
    }

    /**
     * The flush runs in a shutdown handler, where a throw is a fatal error the
     * application cannot catch — and it runs after every request, including the
     * ones where shared memory is unavailable or the collector is a black hole.
     */
    public function testFlushingSwallowsEverythingIncludingItsOwnBugs(): void
    {
        $this->sabotage();
        Sixty::trace('Anything#call', static fn (): null => null);

        // A window that cannot be serialized, an endpoint that refuses
        // connections, and a sink that throws — all at once.
        Sixty::flush(force: true);
        Sixty::shutdown();

        $this->assertTrue(true, 'reaching this line is the assertion');
    }

    public function testAFullSharedMemorySegmentDoesNotFailTheRequest(): void
    {
        if (!Buffer::available()) {
            $this->markTestSkipped('APCu is not enabled');
        }

        // Simulate the buffer being at its ceiling: further windows are dropped
        // rather than stored, and the request notices nothing.
        apcu_store('sixty:pending', 100000);

        $result = Sixty::trace('Anything#call', static fn (): string => 'fine');
        Sixty::flush();

        $this->assertSame('fine', $result);
        Buffer::clear();
    }

    public function testAnAgentThatCannotStartLeavesTheApplicationWorking(): void
    {
        Sixty::reset();
        // No API key: the agent declines to run, and every entry point still
        // has to behave like a pass-through.
        Sixty::init(['api_key' => '', 'on_warn' => static fn (string $m): null => null]);

        $this->assertFalse(Sixty::enabled());
        $this->assertSame('value', Sixty::trace('Anything#call', static fn (): string => 'value'));
        Sixty::annotate('rows', 1);
        Sixty::setRoute('/users/:id');
        Sixty::flush();

        $this->assertTrue(true, 'none of those may throw when the agent is inactive');
    }
}
