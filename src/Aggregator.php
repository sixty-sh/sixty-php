<?php

declare(strict_types=1);

namespace Sixty;

/**
 * In-process rollup.
 *
 * Instead of shipping one record per call, the agent keeps sketches keyed by
 * operation and drains them once. A service doing 50k req/s across 400 distinct
 * operations sends 400 rows per window, not 50k per second.
 *
 * Cardinality is capped here as well as on the collector. Once MAX_OPERATIONS
 * distinct operations are seen, further new ones collapse into a single
 * `__overflow__` bucket and a warning is emitted once. Silently dropping them
 * would make the numbers quietly wrong; unbounded growth would take the host
 * down. Overflow is visible and bounded.
 *
 * ── Why there is no lock, unlike the Ruby one ────────────────────────────
 *
 * PHP-FPM gives a request a process to itself and nothing else runs inside it,
 * so this object is never touched concurrently. The cross-request merging that
 * a threaded agent does under a mutex happens in shared memory instead, one
 * writer per request, with no read-modify-write anywhere — see Buffer.
 */
final class Aggregator
{
    public const MAX_OPERATIONS = 2000;
    public const MAX_EDGES = 5000;

    /** @var array<string, array<string, mixed>> */
    private array $ops = [];
    /** @var array<string, array<string, mixed>> */
    private array $edges = [];
    private bool $overflowed = false;
    private float $windowStart;
    private ?int $memoryCeiling = null;

    /** @var callable(string): void */
    private $onWarn;

    public function __construct(?callable $onWarn = null, private float $alpha = 0.01)
    {
        $this->onWarn = $onWarn ?? static function (string $message): void {
        };
        $this->windowStart = self::nowMs();
    }

    public function record(Span $span): void
    {
        /*
         * Resolved to a key and written through `$this->ops[$key]` rather than
         * through a reference to the row.
         *
         * `$op = &$this->operationFor(...)` reads better and costs more: a
         * reference into an array marks that array as holding references, which
         * turns off some of PHP's copy-on-write handling for the whole
         * structure — and this is the function that runs on every span in the
         * application.
         */
        $key = $span->key ??= self::spanKey($span);
        $this->ensureOperation($key, $span);
        $op = &$this->ops[$key];

        $op['count']++;
        $op['duration']->add((float) $span->duration);
        // Inlined rather than `Tracer::selfTime()`: one static call per span in
        // the application, for a subtraction and a comparison.
        $self = (float) $span->duration - $span->childDuration;
        $op['self']->add($self > 0 ? $self : 0.0);
        // Whether this operation ever called anything. A db span never does, and
        // for anything that never does, self time *is* duration — see
        // serializeOperation, which then encodes one sketch instead of two.
        if ($span->childDuration > 0.0) {
            $op['hasChildren'] = true;
        }

        if ($span->error !== null) {
            $op['errors']++;
            $signature = substr("{$span->error['type']}: {$span->error['message']}", 0, 200);
            $op['errorSamples'][$signature] = ($op['errorSamples'][$signature] ?? 0) + 1;
        }

        // rows: on a db span it is what the query returned; on anything else it
        // is everything its descendants pulled back.
        $rows = $span->kind === Tracer::KIND_DB ? ($span->attrs['rows'] ?? null) : $span->dbRows;
        if (is_numeric($rows)) {
            $op['rows']->add((float) $rows);
            $op['rowsSum'] += (int) $rows;
        }

        if ($span->kind !== Tracer::KIND_DB) {
            $op['dbCallsSum'] += $span->dbCalls;
            if ($span->dbCalls > $op['dbCallsMax']) {
                $op['dbCallsMax'] = $span->dbCalls;
            }
        }

        if (is_numeric($span->attrs['bytes'] ?? null)) {
            $op['bytes']->add((float) $span->attrs['bytes']);
            $op['bytesSum'] += (int) $span->attrs['bytes'];
        }

        // Network round trips, reported only by a client that can make more
        // than one per operation — today that is MongoDB, where a cursor
        // fetches in batches. A sum rather than a sketch, like db calls: what
        // matters is round trips *per call*.
        if (is_numeric($span->attrs['roundTrips'] ?? null)) {
            $op['roundTripsSum'] += (int) $span->attrs['roundTrips'];
        }

        // A plan that arrives after the operation was first recorded still
        // belongs to it, so it is accepted whenever it turns up.
        if ($op['plan'] === null) {
            $op['plan'] = self::planFor($span);
        }

        unset($op);

        if ($span->parent !== null) {
            $this->recordEdge($span->parent, $span, $key);
        }
    }

    /**
     * Serialize and reset. Returns null when there is nothing to send.
     *
     * @return array<string, mixed>|null
     */
    public function drain(): ?array
    {
        if ($this->ops === []) {
            return null;
        }

        $operations = [];
        foreach ($this->ops as $key => $op) {
            $operations[] = self::serializeOperation($key, $op);
        }

        $edges = [];
        foreach ($this->edges as $key => $edge) {
            [$parentKey, $childKey] = explode("\0", $key, 2);
            $edges[] = [
                'parentKey' => $parentKey,
                'childKey' => $childKey,
                'count' => $edge['count'],
                'durationSketch' => $edge['duration']->toBase64(),
                'rowsSum' => $edge['rowsSum'],
            ];
        }

        $payload = [
            'windowStart' => (int) round($this->windowStart),
            'windowEnd' => (int) round(self::nowMs()),
            'operations' => $operations,
            'edges' => $edges,
        ];

        $this->ops = [];
        $this->edges = [];
        $this->overflowed = false;
        $this->windowStart = self::nowMs();

        return $payload;
    }

    public function isEmpty(): bool
    {
        return $this->ops === [];
    }

    /**
     * Stable identity for an operation within one window.
     *
     * A db operation is identified by its statement, never by its label: the
     * label is presentation and will keep improving, and folding it into the
     * key would re-identify the operation — orphaning its history — every time
     * somebody improves the naming.
     */
    public static function spanKey(Span $span): string
    {
        if ($span->kind === Tracer::KIND_DB) {
            $sql = $span->attrs['normalizedSql'] ?? null;

            return "db\x01" . (is_string($sql) && $sql !== '' ? $sql : $span->name);
        }

        return "{$span->kind}\x01{$span->name}";
    }

    /** Create the row this key rolls up into, if it is new. */
    private function ensureOperation(string &$key, Span $span): void
    {
        if (isset($this->ops[$key])) {
            return;
        }

        /*
         * Two ceilings, and the second one is about the host rather than about
         * us.
         *
         * Cardinality is capped at MAX_OPERATIONS, which bounds this object in
         * normal use. What that does not bound is the *application's* headroom:
         * a request already close to `memory_limit` does not care that the
         * agent's share is small and fixed, because exhausting the limit is a
         * fatal error PHP will not let anybody catch — the one failure mode in
         * this package that no try/catch can cover.
         *
         * So new operations stop being learned when the process is near its
         * limit. Everything already known keeps recording, which means the
         * numbers stay right for the operations that matter and the agent stops
         * being a reason the request dies.
         */
        if (count($this->ops) >= self::MAX_OPERATIONS || $this->nearMemoryLimit()) {
            if (!$this->overflowed) {
                $this->overflowed = true;
                ($this->onWarn)(
                    count($this->ops) >= self::MAX_OPERATIONS
                        ? 'sixty: operation cardinality cap (' . self::MAX_OPERATIONS . ') reached; further '
                            . 'operations are grouped as __overflow__. This usually means a route or query '
                            . 'is not being normalized.'
                        : 'sixty: close to memory_limit, so no further operations are being learned. '
                            . 'The ones already known keep reporting.'
                );
            }
            $key = '__overflow__';
            if (!isset($this->ops[$key])) {
                $this->ops[$key] = $this->blankOperation(Tracer::KIND_FUNCTION, '__overflow__');
            }

            return;
        }

        $this->ops[$key] = $this->blankOperation(
            $span->kind,
            $span->name,
            is_string($span->attrs['normalizedSql'] ?? null) ? $span->attrs['normalizedSql'] : null,
            $span->attrs['file'] ?? null,
            $span->attrs['line'] ?? null,
            $span->attrs['frames'] ?? null,
            ($span->attrs['direction'] ?? null) === 'outbound',
            self::planFor($span),
        );
    }

    /** @return array<string, mixed> */
    private function blankOperation(
        string $kind,
        string $name,
        ?string $normalizedSql = null,
        mixed $sourceFile = null,
        mixed $sourceLine = null,
        mixed $frames = null,
        // Something we call, not something we serve — see the note in the JS
        // aggregator. Without it the detector holds a third-party API to the
        // rules written for our own routes.
        bool $outbound = false,
        mixed $plan = null,
    ): array {
        return [
            'kind' => $kind,
            'name' => $name,
            'normalizedSql' => $normalizedSql,
            'sourceFile' => $sourceFile,
            'sourceLine' => $sourceLine,
            'frames' => $frames,
            'outbound' => $outbound,
            'plan' => $plan,
            'count' => 0,
            'errors' => 0,
            'duration' => new Sketch($this->alpha),
            'self' => new Sketch($this->alpha),
            'rows' => new Sketch($this->alpha),
            'rowsSum' => 0,
            'dbCallsSum' => 0,
            'dbCallsMax' => 0,
            'bytes' => new Sketch($this->alpha),
            'bytesSum' => 0,
            'roundTripsSum' => 0,
            'hasChildren' => false,
            'errorSamples' => [],
        ];
    }

    private function recordEdge(Span $parent, Span $child, string $childKey): void
    {
        // Both keys are cached on their spans: the child's was built a moment
        // ago in `record`, and the parent's when the parent was recorded or by
        // its own first edge. Rebuilding them here made three string
        // constructions out of one.
        $key = ($parent->key ??= self::spanKey($parent)) . "\0" . $childKey;
        if (!isset($this->edges[$key])) {
            if (count($this->edges) >= self::MAX_EDGES) {
                return;
            }
            $this->edges[$key] = [
                'count' => 0,
                'duration' => new Sketch($this->alpha),
                'rowsSum' => 0,
            ];
        }
        $this->edges[$key]['count']++;
        $this->edges[$key]['duration']->add((float) $child->duration);
        if (is_numeric($child->attrs['rows'] ?? null)) {
            $this->edges[$key]['rowsSum'] += (int) $child->attrs['rows'];
        }
    }

    /** @return array<string, mixed>|null */
    private static function planFor(Span $span): ?array
    {
        if ($span->kind !== Tracer::KIND_DB) {
            return null;
        }
        $key = $span->attrs['normalizedSql'] ?? null;

        return is_string($key) ? Plans::get($key) : null;
    }

    /**
     * @param array<string, mixed> $op
     * @return array<string, mixed>
     */
    private static function serializeOperation(string $key, array $op): array
    {
        // Sorted and sliced only when something actually failed. The common
        // case is an operation with no errors at all, and sorting an empty
        // array once per operation per request is work for nothing.
        $errorSamples = [];
        if ($op['errorSamples'] !== []) {
            $samples = $op['errorSamples'];
            arsort($samples);
            foreach (array_slice($samples, 0, 5, true) as $message => $count) {
                $errorSamples[] = ['message' => $message, 'count' => $count];
            }
        }

        $duration = $op['duration']->toBase64();
        /*
         * An operation with no children has self time equal to its duration,
         * bucket for bucket — so the two sketches are the same bytes and the
         * second encode is pure waste. Every db operation is in this case, and
         * they are the numerous ones: a request with three queries and one
         * route encodes six sketches instead of eight.
         *
         * The collector still receives both fields, because the wire format is
         * shared with three other agents and a missing field there would be a
         * different kind of problem than a slow one.
         */
        $self = $op['hasChildren'] ? $op['self']->toBase64() : $duration;

        /*
         * Only what was measured.
         *
         * A db operation has no source file and no plan; a route span has no
         * SQL and no row sketch. Writing those out as nulls builds seven more
         * hash entries per operation per request and ships them, and the
         * collector reads every one of these fields defensively — a missing key
         * and an explicit null are the same thing to it. So the row carries
         * what exists, which is both less work here and a smaller payload.
         */
        $operation = [
            'key' => $key,
            'kind' => $op['kind'],
            'name' => $op['name'],
            'count' => $op['count'],
            'errors' => $op['errors'],
            'durationSketch' => $duration,
            'selfSketch' => $self,
            'rowsSum' => $op['rowsSum'],
            'dbCallsSum' => $op['dbCallsSum'],
            'dbCallsMax' => $op['dbCallsMax'],
            'bytesSum' => $op['bytesSum'],
            'roundTripsSum' => $op['roundTripsSum'],
        ];

        if ($op['normalizedSql'] !== null) {
            $operation['normalizedSql'] = $op['normalizedSql'];
        }
        if ($op['sourceFile'] !== null) {
            $operation['sourceFile'] = $op['sourceFile'];
            $operation['sourceLine'] = $op['sourceLine'];
        }
        if ($op['frames'] !== null) {
            $operation['frames'] = $op['frames'];
        }
        // Only ever true or absent: a false on every inbound operation would be
        // bytes per flush to say the ordinary thing.
        if ($op['outbound']) {
            $operation['outbound'] = true;
        }
        if ($op['plan'] !== null) {
            $operation['plan'] = $op['plan'];
        }
        // Null rather than an empty sketch when there is nothing to describe: a
        // counter has no distribution, and an empty one would be
        // indistinguishable from "measured, all zero".
        if ($op['rows']->count() > 0) {
            $operation['rowsSketch'] = $op['rows']->toBase64();
        }
        if ($op['bytes']->count() > 0) {
            $operation['bytesSketch'] = $op['bytes']->toBase64();
        }
        if ($errorSamples !== []) {
            $operation['errorSamples'] = $errorSamples;
        }

        return $operation;
    }

    private static function nowMs(): float
    {
        return microtime(true) * 1000;
    }

    /**
     * Is the process close enough to `memory_limit` that the agent should stop
     * asking for more?
     *
     * Only consulted when an operation is seen for the first time, which after
     * warm-up is almost never — so the check costs nothing on the hot path, and
     * the one place it matters is a process that is growing.
     */
    private function nearMemoryLimit(): bool
    {
        if ($this->memoryCeiling === null) {
            $this->memoryCeiling = self::memoryCeiling();
        }

        return $this->memoryCeiling > 0 && memory_get_usage(true) > $this->memoryCeiling;
    }

    /** 80% of `memory_limit`, or 0 when there is no limit to be near. */
    private static function memoryCeiling(): int
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return 0;
        }

        $bytes = (int) $limit;
        $bytes *= match (strtolower(substr($limit, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return $bytes > 0 ? (int) ($bytes * 0.8) : 0;
    }
}
