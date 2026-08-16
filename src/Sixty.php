<?php

declare(strict_types=1);

namespace Sixty;

/**
 * sixty — zero-configuration performance drift detection, for PHP.
 *
 *     Sixty::init();   // reads SIXTY_API_KEY, SIXTY_SERVICE, SIXTY_RELEASE
 *
 * In Laravel the service provider calls this; in Symfony the bundle does. Both
 * then install the request span, the query instrumentation and the controller
 * span, so an application's whole install is a line in composer.json and a key
 * in the environment.
 *
 * What it reports is deliberately not latency alone. It is *shape*: how many
 * rows a query returned, how many queries a method issued, how much of a
 * request was spent in your own code rather than below it. Those are the
 * numbers that barely move on a warm development database and take production
 * down a week later.
 */
final class Sixty
{
    private static ?Config $config = null;
    private static ?Aggregator $aggregator = null;
    private static ?Exporter $exporter = null;
    private static bool $enabled = false;
    private static bool $shutdownRegistered = false;

    /** @param array<string, mixed> $options */
    public static function init(array $options = []): bool
    {
        if (self::$enabled) {
            return true;
        }

        $config = new Config($options);
        self::$config = $config;

        if (!$config->isActive()) {
            ($config->onWarn)('sixty: no API key found (set SIXTY_API_KEY). Agent is inactive.');

            return false;
        }

        /*
         * Coroutine runtimes are refused rather than half-supported.
         *
         * Swoole runs many requests inside one worker and switches between them
         * at every I/O boundary, so the single "current span" this agent keeps
         * would attribute one request's queries to another request's
         * controller. Wrong attribution is worse than no data: it sends
         * somebody to a file that is not the problem. Process-per-request
         * runtimes — FPM, CLI, RoadRunner, FrankenPHP's worker mode — are all
         * fine and are what the rest of this file assumes.
         */
        if (extension_loaded('swoole') && self::inCoroutine()) {
            ($config->onWarn)(
                'sixty: Swoole coroutines share a worker between requests, which this agent cannot '
                . 'attribute correctly. Agent is inactive.'
            );

            return false;
        }

        self::$aggregator = new Aggregator($config->onWarn);
        self::$exporter = new Exporter(
            endpoint: $config->endpoint,
            apiKey: $config->apiKey,
            service: $config->service,
            environment: $config->environment,
            release: $config->release,
            repoUrl: $config->repoUrl,
            onWarn: $config->onWarn,
        );

        Stack::setRoot($options['root'] ?? getcwd() ?: '');
        Tracer::setSink(self::onSpanEnd(...));
        self::$enabled = true;

        /*
         * MongoDB, if the extension is there.
         *
         * This subscribes to the driver's global command monitoring, which
         * needs no connection and no configuration — so unlike PDO, which is
         * handed to us per connection by the framework, it can and must be
         * installed here. It was documented as automatic before it was, which
         * is the exact silent gap this project refuses: the feature was absent
         * and nothing said so.
         */
        try {
            Instrument\Mongo::install();
        } catch (\Throwable $e) {
            ($config->onWarn)("sixty: could not subscribe to MongoDB: {$e->getMessage()}");
        }

        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(self::shutdown(...));
        }

        if ($config->debug) {
            ($config->onWarn)(sprintf(
                'sixty: active — service=%s env=%s release=%s endpoint=%s buffer=%s',
                $config->service,
                $config->environment,
                $config->release === '' ? '(none)' : $config->release,
                $config->endpoint,
                Buffer::available() ? 'apcu' : 'per-request',
            ));
        }

        if (!Buffer::available()) {
            ($config->onWarn)(
                'sixty: APCu is not enabled, so each request reports its own window instead of '
                . 'sharing one across the pool. This works, but it is one request to the collector '
                . 'per request to you — install ext-apcu for a busy service.'
            );
        }

        return true;
    }

    public static function enabled(): bool
    {
        return self::$enabled;
    }

    public static function config(): ?Config
    {
        return self::$config;
    }

    public static function aggregator(): ?Aggregator
    {
        return self::$aggregator;
    }

    public static function exporter(): ?Exporter
    {
        return self::$exporter;
    }

    /**
     * Measure a callable as one operation.
     *
     * `$name` is an identity that will be compared across releases, so it must
     * not contain anything that varies per call — an id in a name mints an
     * operation per id and blows the cardinality cap.
     *
     * @template T
     * @param callable(): T $work
     * @param array<string, mixed> $attrs
     * @return T
     */
    public static function trace(string $name, callable $work, string $kind = Tracer::KIND_FUNCTION, array $attrs = [])
    {
        if (!self::$enabled) {
            return $work();
        }

        return Tracer::inSpan(Tracer::startSpan($kind, $name, $attrs), $work);
    }

    /**
     * Record a value observed inside the current operation — e.g. the size of a
     * result the caller cares about.
     */
    public static function annotate(string $key, mixed $value): void
    {
        $span = Tracer::current();
        if ($span !== null) {
            $span->attrs[$key] = $value;
        }
    }

    /**
     * Upgrade the current request's span to a framework-supplied route pattern.
     * A real route always beats the path heuristic: without it every /users/42
     * is its own operation.
     */
    public static function setRoute(string $route): void
    {
        $span = Tracer::current();
        if ($span === null || $route === '') {
            return;
        }
        $root = $span->root;
        if ($root !== null && $root->kind === Tracer::KIND_HTTP) {
            $method = $root->attrs['method'] ?? '';
            $root->name = trim("{$method} {$route}");
        }
    }

    /**
     * Called on every completed span. Everything downstream of here is the
     * agent's own work, so it is wrapped: an agent bug must never surface as an
     * application error.
     */
    public static function onSpanEnd(Span $span): void
    {
        try {
            self::$aggregator?->record($span);
            self::keepExemplar($span);
        } catch (\Throwable $e) {
            ($self = self::$config) && ($self->onWarn)("sixty: internal error recording span: {$e->getMessage()}");
        }
    }

    /**
     * End of request.
     *
     * ── The order of the first two lines is the whole point ──────────────
     *
     * `fastcgi_finish_request()` sends the response and closes the connection
     * to the browser while the worker keeps running. Everything after it — the
     * merge, the HTTP POST to the collector, a slow collector, a collector that
     * is down and takes the full timeout — happens after the user already has
     * their page. Without it, every one of those becomes latency somebody
     * waited for.
     */
    public static function shutdown(): void
    {
        if (!self::$enabled) {
            return;
        }

        try {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
        } catch (\Throwable) {
            // A runtime that does not have it is a runtime where the work below
            // simply happens before the process exits.
        }

        self::flush();
    }

    /**
     * Hand this request's window over, and send if the window is due.
     *
     * @param bool $force send now regardless of the interval — for a CLI worker
     *                    that is about to exit, and for tests
     */
    public static function flush(bool $force = false): void
    {
        try {
            self::flushUnguarded($force);
        } catch (\Throwable $e) {
            // Reporting is never worth an exception in somebody's shutdown or
            // terminate handler, where a throw takes the *rest* of their
            // handlers with it.
            ($self = self::$config) && ($self->onWarn)("sixty: flush failed: {$e->getMessage()}");
        }
    }

    private static function flushUnguarded(bool $force): void
    {
        $aggregator = self::$aggregator;
        $exporter = self::$exporter;
        $config = self::$config;
        if ($aggregator === null || $exporter === null || $config === null) {
            return;
        }

        Plans::capturePending();
        Instrument\Pdo::verify($config->onWarn);

        $window = $aggregator->drain();
        $exemplars = $exporter->takeExemplars();

        if (Buffer::available() && !$force) {
            if ($window !== null || $exemplars !== []) {
                Buffer::add($window ?? [], $exemplars);
            }
            if (!Buffer::due($config->flushInterval)) {
                return;
            }
            $pending = Buffer::take();
            if ($pending === null) {
                return;
            }
            $exporter->flush($pending['window'] === [] ? null : $pending['window'], $pending['exemplars']);

            return;
        }

        // No shared memory, or an explicit flush: send what this process holds.
        // `take` still runs so a buffer left behind by a pool that lost APCu
        // mid-life is not stranded.
        if (Buffer::available()) {
            $pending = Buffer::take();
            if ($pending !== null) {
                $window = Buffer::merge(array_filter([$window, $pending['window']])) ?? $window;
                $exemplars = [...$exemplars, ...$pending['exemplars']];
            }
        }

        $exporter->flush($window, $exemplars);
    }

    /** Test seam. Forgets everything. */
    public static function reset(): void
    {
        self::$enabled = false;
        self::$config = null;
        self::$aggregator = null;
        self::$exporter = null;
        Tracer::reset();
        Stack::reset();
        Plans::reset();
    }

    /**
     * Keep the full span tree when it is worth keeping. Aggregates already
     * cover "what happened"; exemplars exist to answer "show me one".
     */
    private static function keepExemplar(Span $span): void
    {
        $config = self::$config;
        $exporter = self::$exporter;
        if ($config === null || $exporter === null || $span->parent !== null) {
            return;
        }

        $isError = $span->error !== null;
        $isSlow = ((float) $span->duration) > $config->slowTraceMs;
        if (!$span->recording && !$isError && !$isSlow) {
            return;
        }

        // The ids exist for this and nothing else, so this is where they are
        // handed out.
        Tracer::assignIds($span);

        $exporter->queueExemplar([
            'traceId' => $span->traceId,
            'startedAt' => $span->startWall,
            'durationMs' => $span->duration,
            'isError' => $isError,
            'reason' => $isError ? 'error' : ($isSlow ? 'slow' : 'sampled'),
            'rootKey' => Aggregator::spanKey($span),
            'spans' => self::flatten($span),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $out
     * @return array<int, array<string, mixed>>
     */
    private static function flatten(Span $span, array $out = [], ?string $parentId = null): array
    {
        $out[] = [
            'id' => $span->id,
            'parentId' => $parentId,
            'kind' => $span->kind,
            'name' => $span->name,
            'durationMs' => round((float) $span->duration, 3),
            'selfMs' => round(Tracer::selfTime($span), 3),
            'dbCalls' => $span->dbCalls,
            'dbRows' => $span->dbRows,
            'attrs' => $span->attrs,
            'error' => $span->error,
        ];
        foreach ($span->children as $child) {
            $out = self::flatten($child, $out, $span->id);
        }

        return $out;
    }

    private static function inCoroutine(): bool
    {
        if (!class_exists('\Swoole\Coroutine', false)) {
            return false;
        }

        /** @var callable(): int|false $get */
        $get = ['\Swoole\Coroutine', 'getCid'];

        return is_callable($get) && $get() > 0;
    }
}
