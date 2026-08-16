<?php

declare(strict_types=1);

namespace Sixty\Instrument;

use Sixty\Tracer;

/**
 * A PDO you construct yourself, measured completely.
 *
 *     $pdo = new Sixty\Instrument\TracedPdo($dsn, $user, $password);
 *
 * For a framework, `Pdo::instrument($existing)` is the right tool: it takes no
 * ownership of a connection somebody else made. This is for the other case —
 * a plain PHP application, a script, a worker — where you own the connection
 * and would rather have `query()` and `exec()` measured too, which the
 * statement-class hook cannot see.
 */
final class TracedPdo extends \PDO
{
    private string $dialect;

    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null)
    {
        parent::__construct($dsn, $username, $password, $options ?? []);
        $this->dialect = Pdo::dialectOf($this);
        Pdo::instrument($this);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        $started = Tracer::monotonicMs();
        try {
            $statement = $fetchMode === null
                ? parent::query($query)
                : parent::query($query, $fetchMode, ...$fetchModeArgs);
        } catch (\Throwable $e) {
            Pdo::record($query, $this->dialect, Tracer::monotonicMs() - $started, null, null, $this, $e);

            throw $e;
        }

        $duration = Tracer::monotonicMs() - $started;
        $rows = null;
        $fields = null;
        if ($statement instanceof \PDOStatement) {
            try {
                $rows = $statement->rowCount();
                $fields = $statement->columnCount();
            } catch (\Throwable) {
                // an unmeasured query, not a failed one
            }
        }
        Pdo::record($query, $this->dialect, $duration, $rows, $fields, $this, null);

        return $statement;
    }

    public function exec(string $statement): int|false
    {
        $started = Tracer::monotonicMs();
        try {
            $affected = parent::exec($statement);
        } catch (\Throwable $e) {
            Pdo::record($statement, $this->dialect, Tracer::monotonicMs() - $started, null, null, $this, $e);

            throw $e;
        }

        Pdo::record(
            $statement,
            $this->dialect,
            Tracer::monotonicMs() - $started,
            is_int($affected) ? $affected : null,
            null,
            $this,
            null,
        );

        return $affected;
    }
}
