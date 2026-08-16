<?php

declare(strict_types=1);

namespace Sixty\Instrument\Laravel;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Sixty\Instrument\Pdo;
use Sixty\Sixty;

/**
 * The whole Laravel install: this provider, discovered from composer.json.
 *
 * Nothing here asks the application for anything. Laravel finds the package,
 * the package finds the key in the environment, and the three things worth
 * measuring wire themselves up:
 *
 *   requests  the middleware, pushed to the front of the global stack
 *   queries   every connection's PDO, as it is established
 *
 * There is deliberately no separate span for the controller action. Laravel
 * runs it inside the middleware stack with no hook that brackets it, and the
 * route already names the request span — so a controller span would be the
 * same operation under a second name. Service objects and jobs are measured
 * with `Sixty::trace()`, which is one line where it is worth having.
 *
 * ── Why the connection event and not DB::listen ──────────────────────────
 *
 * `DB::listen` is the obvious hook and it is the wrong one: it reports the SQL,
 * the bindings and the time, and it does not report how many rows came back.
 * Rows are the signal this product is built on — the 30 → 30,000 that no
 * latency graph shows — so the measurement has to happen where the result is,
 * which is the statement. `ConnectionEstablished` is where a connection's PDO
 * first exists, and instrumenting there costs one attribute per connection and
 * changes nothing about how Laravel uses it.
 */
final class SixtyServiceProvider extends ServiceProvider
{
    /**
     * ── Why both halves are wrapped ──────────────────────────────────────
     *
     * A service provider that throws does not fail to measure — it fails to
     * boot the application. Everything here is optional by definition: a
     * missing `Kernel` binding, a database manager that is not bound, a config
     * key somebody renamed. None of that is a reason for a customer to see a
     * 500, so all of it degrades to a warning and no instrumentation.
     */
    public function register(): void
    {
        try {
            $this->start();
        } catch (\Throwable $e) {
            error_log("sixty: could not start: {$e->getMessage()}");
        }
    }

    private function start(): void
    {
        // SIXTY_SERVICE wins if it is set; the application's own name is a
        // better fallback than "unknown-service", and it is what somebody
        // reading the feed would expect to see.
        Sixty::init([
            'service' => \Sixty\Config::env('SERVICE')
                ?? (string) $this->app['config']->get('app.name', 'laravel'),
            'environment' => (string) ($this->app['config']->get('app.env') ?? 'production'),
            'root' => $this->app->basePath(),
        ]);
    }

    public function boot(): void
    {
        if (!Sixty::enabled()) {
            return;
        }

        try {
            $this->install();
        } catch (\Throwable $e) {
            $config = Sixty::config();
            $config && ($config->onWarn)("sixty: could not install: {$e->getMessage()}");
        }
    }

    private function install(): void
    {
        // Measuring requests is worth having even if the rest fails, and
        // measuring queries is worth having even if the middleware could not be
        // pushed — so neither is allowed to take the other down with it.
        try {
            $this->app->make(Kernel::class)->prependMiddleware(Middleware::class);
        } catch (\Throwable $e) {
            $config = Sixty::config();
            $config && ($config->onWarn)("sixty: no request spans: {$e->getMessage()}");
        }

        // Connections opened from now on, and any that Laravel already made
        // while booting — a package that resolves the database in its own
        // provider is common enough that missing those would look like an
        // application with no queries in it.
        Event::listen(ConnectionEstablished::class, static function (ConnectionEstablished $event): void {
            Pdo::instrument($event->connection->getPdo());
        });

        /*
         * Connections Laravel has *already resolved*, and only those.
         *
         * `getConnections()` is the resolved set; walking the config file
         * instead would name every connection the application has ever
         * configured, and asking each for its PDO would open them — including
         * the MySQL block a Postgres app never deleted, whose host is not
         * listening and whose connect attempt hangs until the request times
         * out. The first version of this file did exactly that, and the demo
         * app died on its first request with a thirty-second timeout inside
         * PDO's constructor.
         *
         * `getRawPdo()` is the other half of the same lesson: before a
         * connection opens it holds a *closure* that would open it, not null —
         * so the check has to be for a real PDO, or the guard guards nothing.
         */
        $existing = [];
        try {
            $existing = DB::getConnections();
        } catch (\Throwable) {
            // An application with no database manager bound is a valid
            // application; it simply has no queries to measure.
        }

        foreach ($existing as $connection) {
            try {
                if (method_exists($connection, 'getRawPdo') && $connection->getRawPdo() instanceof \PDO) {
                    Pdo::instrument($connection->getRawPdo());
                }
            } catch (\Throwable) {
                continue;
            }
        }
    }
}
