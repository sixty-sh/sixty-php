<?php

declare(strict_types=1);

namespace Sixty\Tests;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Sixty\Instrument\Doctrine\Middleware;
use Sixty\Instrument\Symfony\RequestListener;
use Sixty\Sixty;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Symfony and Doctrine, with the real classes.
 *
 * The listener is driven with the events Symfony actually dispatches and the
 * DBAL middleware with a real connection to a real Postgres, because the two
 * things worth checking here cannot be checked against a mock: that `_route`
 * is where the route name lives, and that a middleware-wrapped statement
 * reports how many rows came back.
 */
final class SymfonyTest extends TestCase
{
    use Databases;

    /** @var string[] */
    private array $warnings = [];

    protected function setUp(): void
    {
        if (!class_exists(Kernel::class)) {
            $this->markTestSkipped('symfony/http-kernel is not installed');
        }

        $this->warnings = [];
        Sixty::reset();
        Sixty::init([
            'api_key' => 'k',
            'endpoint' => 'http://127.0.0.1:1',
            'flush_interval' => 3600,
            'sample_rate' => 0,
            'capture_plans' => false,
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

        return $payload === null ? [] : array_column($payload['operations'], null, 'key');
    }

    private function dispatcher(): EventDispatcher
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new RequestListener());

        return $dispatcher;
    }

    private function kernel(): HttpKernelInterface
    {
        return new class () implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response('ok');
            }
        };
    }

    /**
     * A request whose route Symfony resolved is named by the route, not by its
     * path — otherwise /users/42 and /users/43 are two operations and a busy
     * application mints one per id.
     */
    public function testTheRouteNamesTheRequestSpan(): void
    {
        $dispatcher = $this->dispatcher();
        $request = Request::create('/users/42/orders');
        $kernel = $this->kernel();

        $dispatcher->dispatch(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST), 'kernel.request');
        $request->attributes->set('_route', 'app_user_orders');
        $dispatcher->dispatch(
            new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('ok')),
            'kernel.response',
        );

        $operation = array_values($this->drain())[0];

        $this->assertSame('GET app_user_orders', $operation['name']);
        $this->assertSame(1, $operation['count']);
        $this->assertSame(0, $operation['errors']);
    }

    public function testAnUnroutedRequestFallsBackToATemplatedPath(): void
    {
        $dispatcher = $this->dispatcher();
        $request = Request::create('/users/42/orders');
        $kernel = $this->kernel();

        $dispatcher->dispatch(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST), 'kernel.request');
        $dispatcher->dispatch(
            new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('', 404)),
            'kernel.response',
        );

        $this->assertSame('GET /users/:id/orders', array_values($this->drain())[0]['name']);
    }

    /**
     * A 5xx is an error whether or not anything was thrown: the exception may
     * have been rendered into a response by a listener further down, and from
     * the outside those are the same failure.
     */
    public function testAServerErrorIsRecordedAsOne(): void
    {
        $dispatcher = $this->dispatcher();
        $request = Request::create('/orders');
        $kernel = $this->kernel();

        $dispatcher->dispatch(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST), 'kernel.request');
        $dispatcher->dispatch(
            new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('', 500)),
            'kernel.response',
        );

        $this->assertSame(1, array_values($this->drain())[0]['errors']);
    }

    public function testIgnoredPathsAreNotMeasured(): void
    {
        $dispatcher = $this->dispatcher();
        $kernel = $this->kernel();

        foreach (['/_wdt/abc', '/health'] as $path) {
            $request = Request::create($path);
            $dispatcher->dispatch(
                new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST),
                'kernel.request',
            );
            $dispatcher->dispatch(
                new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('ok')),
                'kernel.response',
            );
        }

        $this->assertSame([], $this->drain());
    }

    /**
     * A sub-request — a forwarded controller, a rendered fragment — runs inside
     * the main request's span already. Opening a second root for it would
     * report the same work twice under a name nobody routed to.
     */
    public function testSubRequestsDoNotOpenTheirOwnSpan(): void
    {
        $dispatcher = $this->dispatcher();
        $kernel = $this->kernel();
        $request = Request::create('/fragment');

        $dispatcher->dispatch(new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST), 'kernel.request');
        $dispatcher->dispatch(
            new ResponseEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST, new Response('ok')),
            'kernel.response',
        );

        $this->assertSame([], $this->drain());
    }

    public function testDoctrineQueriesAreMeasuredThroughTheMiddleware(): void
    {
        $this->connectPg() ?? $this->markTestSkipped("no Postgres: {$this->connectError}");

        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware()]);
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => 'localhost',
            'port' => 5433,
            'dbname' => 'drift',
            'user' => 'drift',
            'password' => 'drift',
        ], $configuration);

        $connection->executeStatement('drop table if exists sixty_symfony_orders');
        $connection->executeStatement(
            'create table sixty_symfony_orders (id serial primary key, user_id int not null, email text)'
        );
        $connection->executeStatement(
            "insert into sixty_symfony_orders (user_id, email) values (1,'a@example.com'),(1,'b@example.com'),(2,'c@x')"
        );
        Sixty::aggregator()?->drain();
        \Sixty\Stack::reset();

        Sixty::trace('OrderRepository#findForUser', static function () use ($connection): void {
            $connection->executeQuery('select * from sixty_symfony_orders where user_id = ?', [1])->fetchAllAssociative();
        });

        $ops = $this->drain();
        $query = $ops["db\x01select * from sixty_symfony_orders where user_id = ?"];
        $repository = $ops["function\x01OrderRepository#findForUser"];

        $this->assertSame('select:sixty_symfony_orders', $query['name']);
        $this->assertSame(2, $query['rowsSum'], 'the rows the statement returned');
        $this->assertSame(1, $repository['dbCallsSum'], 'credited to the method that issued it');
        $this->assertSame(2, $repository['rowsSum']);
    }

    public function testDoctrineWritesReportTheRowsTheyChanged(): void
    {
        $this->connectPg() ?? $this->markTestSkipped("no Postgres: {$this->connectError}");

        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware()]);
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => 'localhost',
            'port' => 5433,
            'dbname' => 'drift',
            'user' => 'drift',
            'password' => 'drift',
        ], $configuration);
        Sixty::aggregator()?->drain();

        Sixty::trace('OrderRepository#touch', static function () use ($connection): void {
            $connection->executeStatement('update sixty_symfony_orders set email = email where user_id = ?', [1]);
        });

        $operation = array_values(array_filter($this->drain(), static fn ($op): bool => $op['kind'] === 'db'))[0];

        $this->assertSame(2, $operation['rowsSum']);
    }

    public function testNoLiteralFromADoctrineQueryReachesThePayload(): void
    {
        $this->connectPg() ?? $this->markTestSkipped("no Postgres: {$this->connectError}");

        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware()]);
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => 'localhost',
            'port' => 5433,
            'dbname' => 'drift',
            'user' => 'drift',
            'password' => 'drift',
        ], $configuration);
        Sixty::aggregator()?->drain();

        $connection->executeQuery("select * from sixty_symfony_orders where email = 'alice@example.com'")
            ->fetchAllAssociative();

        $this->assertStringNotContainsString('alice', (string) json_encode(Sixty::aggregator()?->drain()));
    }
}
