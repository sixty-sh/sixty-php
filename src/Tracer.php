<?php

declare(strict_types=1);

namespace Sixty;

/**
 * Span lifecycle and context.
 *
 * The important property, the same one every agent in this repository has:
 * EVERY call is measured, but only a few are *transmitted* as traces. Spans are
 * cheap objects that feed a local rollup; full trees are serialized only for
 * sampled traces, errors and latency outliers.
 *
 * Attribution rules, which are the whole reason this file exists:
 *   self time — duration minus the sum of direct children. "Did MY code get
 *               slower, or did something I called get slower?"
 *   db calls  — every descendant db span, credited to every ancestor. A method
 *               going from 3 to 47 queries is an N+1 being born, and it has to
 *               be visible on the method, not only on the query.
 *   rows      — the same transitive credit. This is the 30 -> 30,000 signal.
 *
 * ── Why "the current span" is a plain static ─────────────────────────────
 *
 * PHP-FPM is share-nothing: one request has one process to itself and nothing
 * runs concurrently inside it, so a static holds exactly one call stack and
 * there is no thread to race with. That is a simplification the Ruby agent
 * cannot make — it needs a mutex and fiber-local storage — and it is worth
 * saying out loud, because it is also the assumption that has to be revisited
 * for the concurrent runtimes: under Swoole coroutines two requests share a
 * worker, and this would then attribute one request's queries to another's
 * controller. Octane on FrankenPHP or RoadRunner is process-per-request and
 * safe; Swoole's coroutine mode is not, which is why Sixty::init() refuses to
 * enable it there rather than reporting numbers nobody can trust.
 */
final class Tracer
{
    public const KIND_HTTP = 'http';
    public const KIND_FUNCTION = 'function';
    public const KIND_DB = 'db';

    /**
     * A single pathological request — the N+1 this product exists to catch —
     * can emit tens of thousands of spans. Aggregates must still count every
     * one of them, but the retained tree is capped so one bad request cannot
     * exhaust memory.
     */
    public const MAX_SPANS_PER_TRACE = 500;

    private static ?Span $current = null;
    private static ?string $traceSeed = null;
    /** @var (callable(Span): void)|null */
    private static $sink = null;
    private static int $seq = 0;

    public static function current(): ?Span
    {
        return self::$current;
    }

    public static function setCurrent(?Span $span): void
    {
        self::$current = $span;
    }

    /** @param (callable(Span): void)|null $sink */
    public static function setSink(?callable $sink): void
    {
        self::$sink = $sink;
    }

    /** @param array<string, mixed> $attrs */
    public static function startSpan(string $kind, string $name, array $attrs = []): Span
    {
        $parent = self::$current;
        $span = new Span($kind, $name, $attrs, $parent);
        $span->start = self::monotonicMs();

        if ($parent !== null) {
            $span->traceId = $parent->traceId;
            $span->depth = $parent->depth + 1;
            $span->root = $parent->root;
        } else {
            // A trace id has to be unique across the fleet, not unguessable:
            // it correlates spans, it is not a credential. One CSPRNG read per
            // *process* plus a counter is as unique as sixteen fresh bytes per
            // request, and does not charge every root span for entropy.
            $span->traceId = (self::$traceSeed ??= bin2hex(random_bytes(12))) . self::hex(++self::$seq);
            $span->depth = 0;
            $span->root = $span;
            $span->startWall = (int) round(microtime(true) * 1000);
        }

        $root = $span->root;
        $root->spanCount++;
        if ($root->spanCount > self::MAX_SPANS_PER_TRACE) {
            $root->truncated = true;
        }

        return $span;
    }

    public static function endSpan(Span $span, ?\Throwable $error = null, ?float $duration = null): Span
    {
        if ($span->duration !== null) {
            return $span; // already ended; guard double-finish
        }

        $span->duration = $duration ?? (self::monotonicMs() - $span->start);
        if ($error !== null) {
            $span->error = [
                // Class name and message only — never the stack trace, which
                // carries file paths, and never the arguments, which are the
                // customer's data.
                //
                // The message is *normalized* rather than truncated, because a
                // driver composes it and a driver will happily quote a value
                // back at you: "Duplicate entry 'alice@example.com' for key
                // 'users_email_unique'" arrives here as "Duplicate entry
                // '<email>' for key 'users_email_unique'". See Sixty\Redact,
                // which is the same rule the browser agent applies.
                'type' => substr($error::class, 0, 200),
                'message' => Redact::message($error->getMessage()) ?? '',
            ];
        }

        $parent = $span->parent;
        if ($parent !== null) {
            $parent->childDuration += $span->duration;
            if (!$span->root->truncated) {
                $parent->children[] = $span;
            }
        }

        // Credit db work to every ancestor, not only the immediate parent.
        if ($span->kind === self::KIND_DB) {
            $rows = is_numeric($span->attrs['rows'] ?? null) ? (int) $span->attrs['rows'] : 0;
            for ($ancestor = $span->parent; $ancestor !== null; $ancestor = $ancestor->parent) {
                $ancestor->dbCalls++;
                $ancestor->dbRows += $rows;
            }
        }

        return $span;
    }

    public static function selfTime(Span $span): float
    {
        return max(0.0, ((float) $span->duration) - $span->childDuration);
    }

    /**
     * Run a callable with $span as the active context, ending and emitting it
     * however the callable leaves — returned value or thrown error.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function inSpan(Span $span, callable $work)
    {
        $previous = self::$current;
        self::$current = $span;
        try {
            $result = $work();
        } catch (\Throwable $e) {
            self::$current = $previous;
            self::endSpan($span, $e);
            self::emit($span);
            throw $e;
        }
        self::$current = $previous;
        self::endSpan($span);
        self::emit($span);

        return $result;
    }

    /**
     * Record a span that has already happened.
     *
     * A query listener hands us an event *after* the work finished, with its
     * own duration — so there is nothing to run a callable around. The span is
     * assembled with that duration and parented to whatever is current, which
     * is correct because the listener runs synchronously in the same request as
     * the query it describes.
     *
     * @param array<string, mixed> $attrs
     */
    public static function record(
        string $kind,
        string $name,
        float $durationMs,
        array $attrs = [],
        ?\Throwable $error = null
    ): Span {
        $span = self::startSpan($kind, $name, $attrs);
        self::endSpan($span, $error, $durationMs);
        self::emit($span);

        return $span;
    }

    /**
     * Handing a span to the sink can never fail the code being measured.
     *
     * This runs inside somebody's request path — between their query returning
     * and their controller resuming — so a throw here does not lose a
     * measurement, it fails their request. Sixty::onSpanEnd catches and warns,
     * which is where an agent bug should be noticed; this is the floor
     * underneath that. Losing one span is recoverable and invisible. Breaking
     * the host application is neither.
     */
    public static function emit(Span $span): void
    {
        $sink = self::$sink;
        if ($sink === null) {
            return;
        }
        try {
            $sink($span);
        } catch (\Throwable) {
            // see above
        }
    }

    public static function monotonicMs(): float
    {
        return hrtime(true) / 1e6;
    }

    public static function reset(): void
    {
        self::$current = null;
        self::$sink = null;
    }

    /**
     * Span ids, handed out only when somebody is going to read them.
     *
     * Nothing uses a span id except an exemplar — the tree of one retained
     * trace — and at the default sample rate that is one request in twenty. So
     * ids are assigned when a trace is flattened rather than when a span is
     * created, which takes the work off every span in the other nineteen.
     */
    public static function assignIds(Span $span, int &$counter = 0): void
    {
        $span->id = self::hex(++$counter);
        foreach ($span->children as $child) {
            $child->parentId = $span->id;
            self::assignIds($child, $counter);
        }
    }

    private static function hex(int $n): string
    {
        return dechex($n);
    }
}
