<?php

declare(strict_types=1);

namespace Sixty\Instrument\Laravel;

use Closure;
use Illuminate\Http\Request;
use Sixty\Sixty;
use Sixty\Sql;
use Sixty\Tracer;
use Symfony\Component\HttpFoundation\Response;

/**
 * The root span of every request, and the naming that decides whether the feed
 * has four hundred rows or four hundred thousand.
 *
 * The route is read *after* the application has run, because that is when
 * Laravel knows it: at the front of the middleware stack — which is where this
 * has to sit to measure the middleware below it — routing has not happened yet.
 * `/users/42` and `/users/43` are the same endpoint and must be one operation,
 * so the path is templated on the way in and replaced by the real route pattern
 * on the way out.
 *
 * ── The rule this class is written to ────────────────────────────────────
 *
 * Nothing the agent does may change what the application returns, and nothing
 * the agent gets wrong may stop it returning at all. Every line that belongs to
 * us is inside a try; the one line that belongs to the application is not
 * wrapped in anything that could swallow or alter it.
 */
final class Middleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        $span = null;
        try {
            $span = $this->start($request);
        } catch (\Throwable) {
            $span = null;
        }

        if ($span === null) {
            return $next($request);
        }

        $previous = Tracer::current();
        Tracer::setCurrent($span);

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            Tracer::setCurrent($previous);
            $this->safely(function () use ($span, $request, $e): void {
                $span->attrs['status'] = 500;
                $this->finish($span, $request, $e);
            });

            throw $e;
        }

        Tracer::setCurrent($previous);
        $this->safely(function () use ($span, $request, $response): void {
            $status = $response instanceof Response ? $response->getStatusCode() : 200;
            $span->attrs['status'] = $status;
            // A 5xx is an error whether or not anything was thrown: the
            // exception may have been rendered into a response three layers
            // down, and from the outside those are the same failure.
            $this->finish($span, $request, $status >= 500 ? new \RuntimeException("HTTP {$status}") : null);
        });

        return $response;
    }

    private function start(Request $request): ?\Sixty\Span
    {
        if (!Sixty::enabled()) {
            return null;
        }
        $config = Sixty::config();
        $path = '/' . ltrim($request->path(), '/');
        if ($config === null || $config->ignores($path)) {
            return null;
        }

        $method = $request->getMethod();
        $span = Tracer::startSpan(Tracer::KIND_HTTP, "{$method} " . Sql::templatePath($path), ['method' => $method]);
        // Head sampling decides whether an *uneventful* trace is retained.
        // Errors and slow outliers are kept regardless, decided at finish time.
        $span->recording = mt_rand() / mt_getrandmax() < $config->sampleRate;

        return $span;
    }

    private function finish(\Sixty\Span $span, Request $request, ?\Throwable $error): void
    {
        $route = $request->route();
        if ($route !== null && method_exists($route, 'uri')) {
            $uri = '/' . ltrim((string) $route->uri(), '/');
            $span->name = trim("{$span->attrs['method']} {$uri}");
        }

        Tracer::endSpan($span, $error);
        Tracer::emit($span);
    }

    private function safely(callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            $config = Sixty::config();
            $config && ($config->onWarn)("sixty: middleware error: {$e->getMessage()}");
        }
    }
}
