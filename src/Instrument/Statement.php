<?php

declare(strict_types=1);

namespace Sixty\Instrument;

use Sixty\Tracer;

/**
 * The statement every instrumented connection hands back.
 *
 * `PDO::ATTR_STATEMENT_CLASS` constructs this with whatever arguments were
 * registered with it, which is how the connection and its dialect arrive here
 * without a global lookup. `$this->queryString` is PDO's own property and holds
 * the statement text, so nothing has to be threaded through `prepare()`.
 *
 * This covers `prepare()` + `execute()`, which is every query an ORM issues:
 * Laravel runs `select`, `insert`, `update` and `delete` through it, and so
 * does Doctrine. `PDO::query()` and `PDO::exec()` do not reach here — PHP
 * executes those inside the engine without calling this class — so an
 * application that calls them directly should use `Sixty\Instrument\TracedPdo`
 * for its own connection. That gap is stated rather than papered over: a
 * measurement nobody knows is missing is worse than one nobody has.
 */
final class Statement extends \PDOStatement
{
    private function __construct(
        private \PDO $pdo,
        private string $dialect,
    ) {
        // PDO constructs this itself; the signature is what ATTR_STATEMENT_CLASS
        // was registered with.
    }

    public function execute(?array $params = null): bool
    {
        $started = Tracer::monotonicMs();

        try {
            $ok = parent::execute($params);
        } catch (\Throwable $e) {
            Pdo::record(
                $this->queryString,
                $this->dialect,
                Tracer::monotonicMs() - $started,
                null,
                null,
                $this->pdo,
                $e,
            );

            throw $e;
        }

        $duration = Tracer::monotonicMs() - $started;

        // Read after the call and guarded: `rowCount` is documented as
        // unreliable for SELECT on some drivers, and the two this agent targets
        // — pgsql and buffered mysql — both report it. A driver that does not
        // gets no row count rather than a wrong one.
        $rows = null;
        $fields = null;
        try {
            $rows = $this->rowCount();
            $fields = $this->columnCount();
        } catch (\Throwable) {
            // an unmeasured query, not a failed one
        }

        Pdo::record($this->queryString, $this->dialect, $duration, $rows, $fields, $this->pdo, null);

        return $ok;
    }
}
