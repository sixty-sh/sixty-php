<?php

declare(strict_types=1);

namespace Sixty\Instrument\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware as DriverMiddleware;

/**
 * Doctrine DBAL, measured at the driver.
 *
 * This is the interface Doctrine intends people to use — `SQLLogger` is
 * deprecated in DBAL 3 and removed in 4 — and it is also the one that can see
 * what this agent needs. A logger reports that a statement ran and how long it
 * took; a middleware wraps the call itself, so the number of rows is simply
 * what the call returned.
 *
 * The chain is three thin decorators, each forwarding to the one below:
 * driver → connection → statement/result. Nothing is copied and nothing is
 * intercepted except the moment a statement is executed.
 */
final class Middleware implements DriverMiddleware
{
    public function wrap(Driver $driver): Driver
    {
        return new TracedDriver($driver);
    }
}
