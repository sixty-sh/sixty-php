<?php

declare(strict_types=1);

namespace Sixty\Instrument\Symfony;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Sixty\Config;
use Sixty\Instrument\Doctrine\Middleware;
use Sixty\Sixty;

/**
 * The whole Symfony install: register this bundle.
 *
 * It starts the agent as the container is built — early enough that a query in
 * a compiler pass or a warm-up command is already measured — and registers two
 * services: the listener that measures requests, and the Doctrine middleware
 * that measures queries.
 *
 * ── Why the Doctrine middleware and not a SQL logger ─────────────────────
 *
 * `SQLLogger` is deprecated in DBAL 3 and gone in 4, and it never reported how
 * many rows came back — which is the signal this product is built on. A
 * `Driver\Middleware` wraps the driver's own statement, so the row count is
 * simply the return value of the call being measured, and it is the interface
 * Doctrine intends people to use.
 */
final class SixtyBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        try {
            $this->wire($container);
        } catch (\Throwable $e) {
            // A bundle that throws while the container is being built takes the
            // whole application with it. Measuring is never worth that.
            error_log("sixty: could not wire into Symfony: {$e->getMessage()}");
        }
    }

    private function wire(ContainerBuilder $container): void
    {

        Sixty::init([
            'service' => Config::env('SERVICE') ?? $this->serviceNameFrom($container),
            'environment' => (string) ($container->getParameter('kernel.environment') ?: 'prod'),
            'root' => (string) ($container->getParameter('kernel.project_dir') ?: getcwd()),
        ]);

        if (!Sixty::enabled()) {
            return;
        }

        $listener = new Definition(RequestListener::class);
        $listener->addTag('kernel.event_subscriber');
        $listener->setPublic(false);
        $container->setDefinition('sixty.request_listener', $listener);

        // Registered only when Doctrine is installed, and by tag rather than by
        // touching doctrine's own configuration — a bundle that rewrites
        // another bundle's definitions breaks on the version that renames them.
        if (class_exists(\Doctrine\DBAL\Driver\Middleware::class)) {
            $middleware = new Definition(Middleware::class);
            $middleware->addTag('doctrine.middleware');
            $middleware->setPublic(false);
            $container->setDefinition('sixty.doctrine_middleware', $middleware);
        }
    }

    private function serviceNameFrom(ContainerBuilder $container): string
    {
        $dir = (string) ($container->getParameter('kernel.project_dir') ?: '');

        return $dir === '' ? 'symfony' : basename($dir);
    }
}
