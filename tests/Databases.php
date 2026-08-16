<?php

declare(strict_types=1);

namespace Sixty\Tests;

/**
 * Connections to the real databases these tests need.
 *
 * Every one skips rather than fails when the server is not there, so the suite
 * is still meaningful on a laptop with nothing running — but CI starts all
 * three as services and asserts that nothing was skipped, so "skipped" never
 * quietly becomes "never run".
 */
trait Databases
{
    private ?string $connectError = null;

    private function pgDsn(): string
    {
        return (string) (getenv('SIXTY_TEST_PG_DSN')
            ?: 'pgsql:host=localhost;port=5433;dbname=drift');
    }

    private function connectPg(): ?\PDO
    {
        try {
            return new \PDO($this->pgDsn(), 'drift', 'drift', [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\Throwable $e) {
            $this->connectError = $e->getMessage();

            return null;
        }
    }

    private function connectMysql(): ?\PDO
    {
        try {
            $dsn = (string) (getenv('SIXTY_TEST_MYSQL_DSN')
                ?: 'mysql:host=127.0.0.1;port=3308;dbname=demo_rails');

            return new \PDO($dsn, 'drift', 'drift', [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\Throwable $e) {
            $this->connectError = $e->getMessage();

            return null;
        }
    }

    private function mongoUri(): string
    {
        return (string) (getenv('SIXTY_TEST_MONGO_URI') ?: 'mongodb://127.0.0.1:27018');
    }
}
