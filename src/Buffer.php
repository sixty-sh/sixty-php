<?php

declare(strict_types=1);

namespace Sixty;

/**
 * Windows waiting to be sent, held across requests.
 *
 * ── The problem PHP has and no other agent in this repository does ────────
 *
 * Every other agent keeps a rollup in memory and flushes it on a timer from a
 * background thread. PHP-FPM has neither: the process handling a request cannot
 * run a timer, and everything it learned is gone when the request ends. The
 * naive consequence is one HTTP POST to the collector per request — at 800
 * requests a second that is 800 POSTs a second to report data whose whole point
 * is that it was aggregated.
 *
 * So the window is handed to shared memory instead. Each request writes what it
 * measured under a key of its own, and one request in every flush interval
 * collects them, merges them, and sends a single payload.
 *
 * ── Why a key per request and not one shared buffer ──────────────────────
 *
 * A shared buffer means read-modify-write, and APCu has no compare-and-swap for
 * anything but integers. Two workers finishing at the same moment would both
 * read the same buffer and one would overwrite the other — losing a request's
 * worth of measurements, silently, under exactly the load that makes the
 * measurements matter. Writing to a unique key cannot collide; only the flush
 * needs a lock, and losing *that* race is harmless because the loser simply
 * leaves the windows for the winner.
 *
 * ── When APCu is not installed ───────────────────────────────────────────
 *
 * The agent falls back to sending each request's own window, which is correct
 * but chatty, and says so once when it starts. It is not fatal, and pretending
 * otherwise — refusing to run without an optional extension — would be the
 * wrong trade for a tool whose job is to be installed everywhere.
 */
final class Buffer
{
    private const PREFIX = 'sixty:w:';
    private const LOCK = 'sixty:flushing';
    private const WINDOW_START = 'sixty:window';
    private const PENDING = 'sixty:pending';

    /**
     * A ceiling on how many unsent windows may pile up — a collector that has
     * been down for an hour must not fill the shared memory segment the
     * application's own cache lives in.
     */
    private const MAX_PENDING = 500;

    public static function available(): bool
    {
        return function_exists('apcu_store') && function_exists('apcu_enabled') && apcu_enabled();
    }

    /**
     * Hand this request's window over. Returns false when there is no shared
     * memory to hand it to, and the caller sends it itself.
     *
     * @param array<string, mixed> $window
     * @param array<int, array<string, mixed>> $exemplars
     */
    public static function add(array $window, array $exemplars): bool
    {
        if (!self::available()) {
            return false;
        }

        // A counter rather than a scan of the pending keys.
        //
        // Counting by iterating APCu is O(pending) on *every request*, which
        // makes the whole buffer quadratic in how many requests arrive between
        // flushes — and the measurement is unambiguous: 168µs per request
        // scanning, 12µs incrementing. `apcu_inc` is atomic, so no two workers
        // can lose each other's increment.
        $pending = apcu_inc(self::PENDING, 1, $created);
        if ($pending !== false && $pending > self::MAX_PENDING) {
            apcu_dec(self::PENDING, 1);

            return false;
        }

        // The key carries the time so the collector's window bounds survive
        // being merged, and enough randomness that two workers finishing in the
        // same microsecond cannot choose the same one.
        $key = self::PREFIX . microtime(true) . ':' . bin2hex(random_bytes(6));
        apcu_add(self::WINDOW_START, microtime(true), 0);

        return apcu_store($key, ['window' => $window, 'exemplars' => $exemplars], 3600);
    }

    /** Has the current window been open longer than the flush interval? */
    public static function due(float $intervalSeconds): bool
    {
        if (!self::available()) {
            return false;
        }
        $started = apcu_fetch(self::WINDOW_START);

        return !is_float($started) || (microtime(true) - $started) >= $intervalSeconds;
    }

    /**
     * Take everything pending, merged into one payload. Returns null when
     * another request is already doing it, or when there is nothing to send.
     *
     * The lock is `apcu_add`, which is atomic: exactly one caller creates the
     * key and the rest see it exist. Its TTL is what makes a worker that dies
     * mid-flush cost one interval rather than every interval after it.
     *
     * @return array{window: array<string, mixed>, exemplars: array<int, array<string, mixed>>}|null
     */
    public static function take(int $lockSeconds = 30): ?array
    {
        if (!self::available()) {
            return null;
        }
        if (!apcu_add(self::LOCK, 1, $lockSeconds)) {
            return null;
        }

        $keys = [];
        foreach (new \APCUIterator('/^' . preg_quote(self::PREFIX, '/') . '/', APC_ITER_KEY) as $item) {
            $keys[] = $item['key'];
        }
        if ($keys === []) {
            apcu_delete(self::LOCK);

            return null;
        }

        $windows = [];
        $exemplars = [];
        foreach ($keys as $key) {
            $entry = apcu_fetch($key);
            apcu_delete($key);
            if (!is_array($entry)) {
                continue;
            }
            if (isset($entry['window']) && is_array($entry['window'])) {
                $windows[] = $entry['window'];
            }
            if (isset($entry['exemplars']) && is_array($entry['exemplars'])) {
                foreach ($entry['exemplars'] as $exemplar) {
                    $exemplars[] = $exemplar;
                }
            }
        }

        apcu_store(self::WINDOW_START, microtime(true), 0);
        apcu_store(self::PENDING, 0, 0);
        apcu_delete(self::LOCK);

        $merged = self::merge($windows);
        if ($merged === null && $exemplars === []) {
            return null;
        }

        return [
            'window' => $merged ?? [],
            // Capped on the way out: the collector takes 100 per flush and the
            // rest would be serialized, sent and discarded.
            'exemplars' => array_slice($exemplars, 0, 100),
        ];
    }

    /**
     * Merge many drained windows into one.
     *
     * The counters add and the sketches merge — losslessly, which is the
     * property that makes this legitimate rather than an approximation. Two
     * requests that each saw the same operation produce one row whose
     * percentiles are exactly what a single process measuring both would have
     * reported.
     *
     * ── Why the sketches are decoded once and encoded once ───────────────
     *
     * The obvious spelling merges pairwise: take the accumulated blob, decode
     * it, merge the next one in, encode it again. That decodes and re-encodes
     * the *accumulated* sketch once per window, so merging N windows costs N
     * encodes of a sketch that is itself growing — quadratic, and measurably:
     * 76µs per window at fifty windows and 112µs at two hundred, on a path
     * that runs once per flush interval on a live worker.
     *
     * Accumulating into sketch objects and encoding at the end makes it linear
     * and costs nothing in accuracy, because a decode/encode round trip is
     * exact: same alpha, same buckets, same sum.
     *
     * @param array<int, array<string, mixed>> $windows
     * @return array<string, mixed>|null
     */
    public static function merge(array $windows): ?array
    {
        if ($windows === []) {
            return null;
        }

        /** @var array<string, array<string, mixed>> $operations */
        $operations = [];
        /** @var array<string, array<string, string[]>> $sketches */
        $sketches = [];
        $edges = [];
        $edgeSketches = [];
        $windowStart = null;
        $windowEnd = 0;

        foreach ($windows as $window) {
            $windowStart = $windowStart === null
                ? ($window['windowStart'] ?? null)
                : min($windowStart, $window['windowStart'] ?? $windowStart);
            $windowEnd = max($windowEnd, (int) ($window['windowEnd'] ?? 0));

            foreach ($window['operations'] ?? [] as $op) {
                $key = $op['key'] ?? '';
                if (!isset($operations[$key])) {
                    $operations[$key] = $op;
                    $sketches[$key] = [];
                    foreach (self::SKETCH_FIELDS as $field) {
                        $blob = $op[$field] ?? null;
                        $sketches[$key][$field] = is_string($blob) && $blob !== '' ? [$blob] : [];
                    }
                    continue;
                }
                self::mergeCounters($operations[$key], $op);
                foreach (self::SKETCH_FIELDS as $field) {
                    $blob = $op[$field] ?? null;
                    if (is_string($blob) && $blob !== '') {
                        $sketches[$key][$field][] = $blob;
                    }
                }
            }

            foreach ($window['edges'] ?? [] as $edge) {
                $key = ($edge['parentKey'] ?? '') . "\0" . ($edge['childKey'] ?? '');
                if (!isset($edges[$key])) {
                    $edges[$key] = $edge;
                    $blob = $edge['durationSketch'] ?? null;
                    $edgeSketches[$key] = is_string($blob) && $blob !== '' ? [$blob] : [];
                    continue;
                }
                $edges[$key]['count'] += $edge['count'] ?? 0;
                $edges[$key]['rowsSum'] += $edge['rowsSum'] ?? 0;
                $blob = $edge['durationSketch'] ?? null;
                if (is_string($blob) && $blob !== '') {
                    $edgeSketches[$key][] = $blob;
                }
            }
        }

        foreach ($operations as $key => $operation) {
            $encoded = [];
            foreach (self::SKETCH_FIELDS as $field) {
                $blobs = $sketches[$key][$field] ?? [];
                /*
                 * `selfSketch` is byte-identical to `durationSketch` for every
                 * operation that never called anything — the aggregator emits
                 * the same string twice on purpose. Comparing the two lists
                 * costs a pointer comparison per window and saves decoding and
                 * re-encoding the whole thing a second time, which on a service
                 * whose operations are mostly queries is a third of the merge.
                 */
                if ($field === 'selfSketch' && $blobs === ($sketches[$key]['durationSketch'] ?? [])) {
                    $encoded[$field] = $encoded['durationSketch'] ?? null;
                    continue;
                }
                $encoded[$field] = match (count($blobs)) {
                    0 => null,
                    // One window saw this operation and no other did: its bytes
                    // are already the answer.
                    1 => $blobs[0],
                    default => Sketch::mergeEncoded($blobs),
                };
            }
            foreach ($encoded as $field => $value) {
                $operations[$key][$field] = $value;
            }
        }
        foreach ($edges as $key => $edge) {
            $blobs = $edgeSketches[$key] ?? [];
            $edges[$key]['durationSketch'] = match (count($blobs)) {
                0 => null,
                1 => $blobs[0],
                default => Sketch::mergeEncoded($blobs),
            };
        }

        return [
            'windowStart' => (int) ($windowStart ?? $windowEnd),
            'windowEnd' => $windowEnd,
            'operations' => array_values($operations),
            'edges' => array_values($edges),
        ];
    }

    private const SKETCH_FIELDS = ['durationSketch', 'selfSketch', 'rowsSketch', 'bytesSketch'];

    /**
     * Fold one window's counters into the accumulated operation.
     *
     * By reference, and returning nothing: taking the array by value and
     * returning it copies a twenty-key array per operation per window, which on
     * a flush of two hundred windows is eight hundred array copies for no
     * reason. PHP's copy-on-write does not save you here, because the write
     * happens immediately.
     *
     * @param array<string, mixed> $into
     * @param array<string, mixed> $from
     */
    private static function mergeCounters(array &$into, array $from): void
    {
        foreach (['count', 'errors', 'rowsSum', 'dbCallsSum', 'bytesSum', 'roundTripsSum'] as $field) {
            $into[$field] = ($into[$field] ?? 0) + ($from[$field] ?? 0);
        }
        $into['dbCallsMax'] = max($into['dbCallsMax'] ?? 0, $from['dbCallsMax'] ?? 0);

        // The stack is captured once per process, so most windows carry none —
        // keeping whichever one has it rather than the first one seen.
        $into['frames'] ??= $from['frames'] ?? null;
        $into['plan'] ??= $from['plan'] ?? null;
        $into['sourceFile'] ??= $from['sourceFile'] ?? null;
        $into['sourceLine'] ??= $from['sourceLine'] ?? null;

        // Skipped entirely for the overwhelmingly common case of an operation
        // that did not fail: two empty arrays spread into a third, sorted, and
        // sliced, once per operation per window, to produce nothing.
        if (($into['errorSamples'] ?? []) === [] && ($from['errorSamples'] ?? []) === []) {
            return;
        }

        $samples = [];
        foreach ([...$into['errorSamples'] ?? [], ...$from['errorSamples'] ?? []] as $sample) {
            $message = $sample['message'] ?? '';
            $samples[$message] = ($samples[$message] ?? 0) + ($sample['count'] ?? 0);
        }
        arsort($samples);
        $into['errorSamples'] = [];
        foreach (array_slice($samples, 0, 5, true) as $message => $count) {
            $into['errorSamples'][] = ['message' => $message, 'count' => $count];
        }
    }

    /**
     * Fold one more encoded sketch into an accumulator.
     *
     * The accumulator is a *string* until a second sketch turns up for the same
     * field, and only then becomes a Sketch. Most operations appear in one
     * window of an interval and never merge with anything — for those this
     * skips a decode and an encode entirely, which at ~1µs each is most of what
     * the flush costs on a quiet service.
     *
     * @param Sketch|string|null $into
     * @return Sketch|string|null
     */
    private static function accumulate(Sketch|string|null $into, mixed $blob): Sketch|string|null
    {
        if (!is_string($blob) || $blob === '') {
            return $into;
        }
        if ($into === null) {
            return $blob;
        }

        $accumulator = is_string($into) ? self::decode($into) : $into;
        $incoming = self::decode($blob);
        if ($accumulator === null) {
            return $incoming ?? $into;
        }

        return $incoming === null ? $accumulator : $accumulator->merge($incoming);
    }

    /** @param Sketch|string|null $sketch */
    private static function encode(Sketch|string|null $sketch): ?string
    {
        if ($sketch === null) {
            return null;
        }

        return is_string($sketch) ? $sketch : $sketch->toBase64();
    }

    public static function clear(): void
    {
        if (!self::available()) {
            return;
        }
        foreach (new \APCUIterator('/^sixty:/', APC_ITER_KEY) as $item) {
            apcu_delete($item['key']);
        }
    }
}
