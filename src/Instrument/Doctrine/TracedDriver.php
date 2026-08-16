<?php

declare(strict_types=1);

namespace Sixty\Instrument\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Sixty\Sql;

/**
 * Hands back a connection that measures its statements.
 *
 * The dialect is decided here, once per connection, from the driver's own
 * platform rather than guessed from the SQL — because `"alice@example.com"` is
 * a quoted identifier in Postgres and a string literal in MySQL, and reading
 * one with the other's rules would transmit it.
 */
final class TracedDriver extends AbstractDriverMiddleware
{
    /** @param array<string, mixed> $params */
    public function connect(array $params): Connection
    {
        $connection = parent::connect($params);

        return new TracedConnection($connection, self::dialectOf($params));
    }

    /** @param array<string, mixed> $params */
    private static function dialectOf(array $params): string
    {
        $driver = (string) ($params['driver'] ?? '');
        $name = $driver !== '' ? $driver : (string) ($params['driverClass'] ?? '');

        return str_contains(strtolower($name), 'mysql') ? Sql::MYSQL : Sql::POSTGRES;
    }
}
