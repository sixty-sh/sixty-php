<?php

declare(strict_types=1);

namespace Sixty\Instrument\Symfony;

use Sixty\Sixty;
use Sixty\Span;
use Sixty\Sql;
use Sixty\Tracer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The root span of every request.
 *
 * Symfony hands us exactly the hooks this needs: `kernel.request` at the
 * highest priority so the span covers everything the framework then does,
 * `kernel.response` to close it with a status, and `_route` — the route's own
 * name — so `/users/42` and `/users/43` are one operation rather than two
 * thousand.
 *
 * Sub-requests are skipped. A forwarded request or a rendered ESI fragment runs
 * inside the master request's span already, and opening a second root for it
 * would report the same work twice under a name nobody routed to.
 */
final class RequestListener implements EventSubscriberInterface
{
    private ?Span $span = null;

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 4096],
            KernelEvents::RESPONSE => ['onResponse', -4096],
            KernelEvents::EXCEPTION => ['onException', -4096],
            KernelEvents::TERMINATE => ['onTerminate', -4096],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !Sixty::enabled() || $this->span !== null) {
            return;
        }

        try {
            $config = Sixty::config();
            $path = $event->getRequest()->getPathInfo();
            if ($config === null || $config->ignores($path)) {
                return;
            }

            $method = $event->getRequest()->getMethod();
            $this->span = Tracer::startSpan(
                Tracer::KIND_HTTP,
                "{$method} " . Sql::templatePath($path),
                ['method' => $method],
            );
            $this->span->recording = mt_rand() / mt_getrandmax() < $config->sampleRate;
            Tracer::setCurrent($this->span);
        } catch (\Throwable) {
            $this->span = null;
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || $this->span === null) {
            return;
        }

        $status = $event->getResponse()->getStatusCode();
        $this->span->attrs['status'] = $status;
        // A 5xx is an error whether or not anything was thrown: the exception
        // may have been turned into a response by an listener further down, and
        // from the outside those are the same failure.
        $this->close(
            $event->getRequest()->attributes->get('_route'),
            $status >= 500 ? new \RuntimeException("HTTP {$status}") : null,
        );
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest() || $this->span === null) {
            return;
        }

        $this->span->attrs['status'] = 500;
        $this->close($event->getRequest()->attributes->get('_route'), $event->getThrowable());
    }

    /**
     * The last thing Symfony does, and the right place to hand the window over:
     * the response has been sent, so nothing after this is time the user waits
     * for. `Sixty::shutdown()` would do it anyway, but doing it here means a
     * long-running worker — Runtime, RoadRunner, FrankenPHP — flushes per
     * request instead of never.
     */
    public function onTerminate(TerminateEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        // `flush()` swallows its own failures — a throw here would take the
        // application's other terminate listeners with it.
        Sixty::flush();
    }

    private function close(mixed $route, ?\Throwable $error): void
    {
        $span = $this->span;
        if ($span === null) {
            return;
        }
        $this->span = null;

        try {
            if (is_string($route) && $route !== '') {
                $span->name = trim("{$span->attrs['method']} {$route}");
            }
            Tracer::setCurrent(null);
            Tracer::endSpan($span, $error);
            Tracer::emit($span);
        } catch (\Throwable $e) {
            $config = Sixty::config();
            $config && ($config->onWarn)("sixty: listener error: {$e->getMessage()}");
        }
    }
}
