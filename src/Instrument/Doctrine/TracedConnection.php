<?php

declare(strict_types=1);

namespace Sixty\Instrument\Doctrine;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Sixty\Instrument\Pdo;
use Sixty\Tracer;

/**
 * A connection whose statements are measured.
 *
 * Both paths a query can take are covered here, and they cannot both fire for
 * one round trip: `query()` and `exec()` run a statement directly, while
 * `prepare()` hands back a statement object whose own `execute()` is wrapped.
 */
final class TracedConnection extends AbstractConnectionMiddleware
{
    public function __construct(Connection $connection, private string $dialect)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        return new TracedStatement(parent::prepare($sql), $sql, $this->dialect, $this->nativePdo());
    }

    public function query(string $sql): Result
    {
        $started = Tracer::monotonicMs();
        try {
            $result = parent::query($sql);
        } catch (\Throwable $e) {
            Pdo::record($sql, $this->dialect, Tracer::monotonicMs() - $started, null, null, $this->nativePdo(), $e);

            throw $e;
        }

        Pdo::record(
            $sql,
            $this->dialect,
            Tracer::monotonicMs() - $started,
            self::rowsOf($result),
            self::columnsOf($result),
            $this->nativePdo(),
            null,
        );

        return $result;
    }

    public function exec(string $sql): int|string
    {
        $started = Tracer::monotonicMs();
        try {
            $affected = parent::exec($sql);
        } catch (\Throwable $e) {
            Pdo::record($sql, $this->dialect, Tracer::monotonicMs() - $started, null, null, $this->nativePdo(), $e);

            throw $e;
        }

        Pdo::record(
            $sql,
            $this->dialect,
            Tracer::monotonicMs() - $started,
            (int) $affected,
            null,
            $this->nativePdo(),
            null,
        );

        return $affected;
    }

    /**
     * The PDO underneath, when there is one — it is what an EXPLAIN would be
     * issued on, and a driver that is not PDO simply gets no query plans rather
     * than an error.
     */
    private function nativePdo(): ?\PDO
    {
        $native = $this->getNativeConnection();

        return $native instanceof \PDO ? $native : null;
    }

    public static function rowsOf(Result $result): ?int
    {
        try {
            $rows = $result->rowCount();
        } catch (\Throwable) {
            return null;
        }

        return is_int($rows) ? $rows : null;
    }

    public static function columnsOf(Result $result): ?int
    {
        try {
            return $result->columnCount();
        } catch (\Throwable) {
            return null;
        }
    }
}
