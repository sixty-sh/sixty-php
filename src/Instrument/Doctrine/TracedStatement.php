<?php

declare(strict_types=1);

namespace Sixty\Instrument\Doctrine;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Sixty\Instrument\Pdo;
use Sixty\Tracer;

/**
 * A prepared statement, measured where it executes.
 *
 * The SQL arrives from `prepare()` and is held here, so the parameters bound
 * later never have to be looked at — which is the point: they are the
 * customer's data, and an agent that reads them in order to describe a query
 * has already lost the argument.
 */
final class TracedStatement extends AbstractStatementMiddleware
{
    public function __construct(
        Statement $statement,
        private string $sql,
        private string $dialect,
        private ?\PDO $pdo,
    ) {
        parent::__construct($statement);
    }

    public function execute(): Result
    {
        $started = Tracer::monotonicMs();
        try {
            $result = parent::execute();
        } catch (\Throwable $e) {
            Pdo::record($this->sql, $this->dialect, Tracer::monotonicMs() - $started, null, null, $this->pdo, $e);

            throw $e;
        }

        Pdo::record(
            $this->sql,
            $this->dialect,
            Tracer::monotonicMs() - $started,
            TracedConnection::rowsOf($result),
            TracedConnection::columnsOf($result),
            $this->pdo,
            null,
        );

        return $result;
    }
}
