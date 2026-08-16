<?php

declare(strict_types=1);

namespace Sixty\Instrument;

use Sixty\Plans;
use Sixty\Sixty;
use Sixty\Sql;
use Sixty\Stack;
use Sixty\Tracer;

/**
 * PDO, instrumented without replacing anybody's connection.
 *
 * ── Why the statement class and not a wrapper ────────────────────────────
 *
 * The obvious approach is a decorator around PDO. It does not work here: both
 * Laravel and Doctrine type-hint `\PDO` and hold the instance they created, so
 * a decorator would have to be a subclass, and a subclass has to open its own
 * connection — which means an agent that silently doubles the connection count
 * of every application that installs it.
 *
 * `PDO::ATTR_STATEMENT_CLASS` sets the class that `prepare()` and `query()`
 * return, and it can be set on a connection that already exists. So the
 * application keeps its own PDO, its own pool, its own connection, and every
 * statement it prepares arrives here on the way through. One attribute, no
 * ownership taken.
 *
 * ── What is captured beyond timing ───────────────────────────────────────
 *
 *   rows   : `rowCount()`, which on Postgres and MySQL is the rows a SELECT
 *            returned and the rows a write changed. The 30 → 30,000 signal.
 *   fields : column count, which is how `select *` creeping into a hot path
 *            becomes visible.
 *
 * Never the SQL text, and never a parameter: only the normalized shape.
 *
 * ── What it misses, said out loud ────────────────────────────────────────
 *
 * `PDO::exec()` returns an integer rather than a statement, so it never reaches
 * this class. That covers DDL and Laravel's `unprepared()`, which is a small
 * and deliberate corner of most applications — but it is a hole, and a hole
 * nobody wrote down is the failure this project exists to prevent.
 */
final class Pdo
{
    /** @var \WeakMap<\PDO, string>|null dialect per connection */
    private static ?\WeakMap $dialects = null;
    private static bool $displaced = false;

    /**
     * Point a connection at the instrumented statement class.
     *
     * Returns false when the connection already has a statement class of
     * somebody else's — a profiler, a framework debug bar — because taking it
     * would break them, and a missing measurement is better than a broken
     * application.
     */
    public static function instrument(\PDO $pdo): bool
    {
        try {
            $existing = $pdo->getAttribute(\PDO::ATTR_STATEMENT_CLASS);
            if (is_array($existing) && isset($existing[0])
                && $existing[0] !== \PDOStatement::class
                && $existing[0] !== Statement::class) {
                return false;
            }

            $dialect = self::dialectOf($pdo);
            self::dialects()[$pdo] = $dialect;

            // The statement is handed the connection it came from so it can
            // queue an EXPLAIN against it later, and the dialect so it lexes
            // MySQL by MySQL's rules rather than Postgres's.
            return $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [Statement::class, [$pdo, $dialect]]);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Are the connections we instrumented still instrumented?
     *
     * `PDO::ATTR_STATEMENT_CLASS` is a slot with one occupant. This agent
     * declines to take it from somebody who got there first — but the reverse
     * can also happen, and it is worse: a profiler or a debug bar registered
     * after boot takes the slot, and from then on this package measures no
     * queries at all and says nothing. A measurement that stops is
     * indistinguishable from an application that stopped querying, which is
     * exactly the silence this project exists to eliminate.
     *
     * Checked once per flush, off the request path, and reported once.
     */
    public static function verify(callable $onWarn): void
    {
        if (self::$displaced) {
            return;
        }

        foreach (self::dialects() as $pdo => $ignored) {
            try {
                $class = $pdo->getAttribute(\PDO::ATTR_STATEMENT_CLASS);
            } catch (\Throwable) {
                continue;
            }
            if (is_array($class) && ($class[0] ?? null) !== Statement::class) {
                self::$displaced = true;
                $onWarn(
                    'sixty: something else has taken PDO::ATTR_STATEMENT_CLASS on a connection, so '
                    . 'queries on it are no longer being measured. Usually a profiler or a debug bar.'
                );

                return;
            }
        }
    }

    public static function dialectOf(\PDO $pdo): string
    {
        try {
            $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        } catch (\Throwable) {
            return Sql::POSTGRES;
        }

        return str_contains($driver, 'mysql') ? Sql::MYSQL : Sql::POSTGRES;
    }

    /** @return \WeakMap<\PDO, string> */
    private static function dialects(): \WeakMap
    {
        /** @var \WeakMap<\PDO, string> */
        return self::$dialects ??= new \WeakMap();
    }

    /**
     * Record one statement execution. Shared by the statement class and by any
     * adapter that measures a query some other way, so the rules — never throw,
     * capture frames once, queue a plan — live in one place.
     */
    public static function record(
        string $sql,
        string $dialect,
        float $durationMs,
        ?int $rows,
        ?int $fields,
        ?\PDO $pdo,
        ?\Throwable $error,
    ): void {
        if (!Sixty::enabled() || $sql === '') {
            return;
        }

        try {
            $normalized = Sql::normalize($sql, $dialect);
            $attrs = ['normalizedSql' => $normalized];

            // Once per distinct statement, never again: the call site is a
            // property of the query, not of the call.
            $frames = Stack::capture($normalized);
            if ($frames !== null) {
                $attrs['frames'] = $frames;
                $attrs['file'] = $frames[0]['file'];
                $attrs['line'] = $frames[0]['line'];
            }
            if ($rows !== null) {
                $attrs['rows'] = $rows;
            }
            if ($fields !== null) {
                $attrs['fields'] = $fields;
            }

            Tracer::record(
                Tracer::KIND_DB,
                Sql::operationName($normalized),
                $durationMs,
                $attrs,
                $error,
            );

            $config = Sixty::config();
            if ($pdo !== null && $config !== null && $config->capturePlans) {
                Plans::enqueue($sql, $normalized, $dialect, $pdo);
            }
        } catch (\Throwable) {
            // An unmeasured query, not a failed one.
        }
    }
}
