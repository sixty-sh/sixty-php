<?php

declare(strict_types=1);

namespace Sixty;

/**
 * A query plan, reduced to its shape.
 *
 * ── Why this is the signal worth having ───────────────────────────────────
 *
 * Everything else this agent measures is a symptom: rows went up, latency went
 * up, a method makes more queries than it did. A plan change is the *cause* —
 * the moment a query stops using an index is the moment it becomes the
 * incident, and every other number only reflects it afterwards, usually days
 * afterwards, once the table is large enough for the difference to show.
 *
 * It is also the finding a person can act on without understanding any of this.
 * "This got slower" invites a shrug; "this stopped using orders_user_id_idx"
 * names the fix.
 *
 * ── The rules that make this safe to run in production ────────────────────
 *
 * 1. EXPLAIN, never EXPLAIN ANALYZE. ANALYZE *executes* the statement.
 * 2. GENERIC_PLAN, so no parameter is ever bound. Postgres 16 added it exactly
 *    for this, and it keeps the privacy boundary intact by construction rather
 *    than by a promise to strip values afterwards.
 * 3. Read-only statements only, refused before they reach the database.
 * 4. Off the response path. In PHP this is unusually clean: the EXPLAIN runs
 *    from `Sixty::shutdown()`, which has already called
 *    `fastcgi_finish_request()`, so the user has their page and the worker is
 *    doing bookkeeping on its own time.
 *
 * ── Two refusals, both about values ──────────────────────────────────────
 *
 * **MySQL gets no plans at all.** It has no equivalent of GENERIC_PLAN: a
 * statement can only be explained with its parameters bound, so capturing a
 * plan would mean *retaining* somebody's query values in order to compose a
 * command out of them. `@sixty-sh/node` and the Ruby agent refuse this for the
 * same reason, and MongoDB is refused a third time on the same grounds.
 *
 * **A statement that arrived carrying literals is refused too**, even on
 * Postgres, because it would have to be held until the flush. The lexer decides
 * which is which — the question is exactly "would normalization have removed
 * anything".
 */
final class Plans
{
    private const MAX_PLANS = 500;
    private const MAX_PENDING = 50;

    /** Only statements that read. */
    private const READ_ONLY = '/\A\s*(select|with)\b/i';
    /** A statement already carrying its own EXPLAIN, or several at once. */
    private const UNSAFE = '/\A\s*explain\b|;\s*\S/i';

    private const STRUCTURAL = [
        'Node Type', 'Join Type', 'Strategy', 'Relation Name',
        'Index Name', 'Scan Direction', 'Parent Relationship',
    ];

    private const MAX_DEPTH = 12;
    private const MAX_NODES = 120;

    /** @var array<string, array<string, mixed>> */
    private static array $plans = [];
    /** @var array<string, array{sql: string, pdo: \PDO}> */
    private static array $pending = [];
    /** @var array<string, true> */
    private static array $attempted = [];

    /** @return array<string, mixed>|null */
    public static function get(string $key): ?array
    {
        return self::$plans[$key] ?? null;
    }

    /**
     * Remember a statement to explain when the response is already out.
     *
     * `attempted` is marked here rather than on success, so a statement the
     * planner refuses — an older Postgres with no GENERIC_PLAN, an untypable
     * parameter, a temp table that no longer exists — is tried once and then
     * left alone instead of retried on every execution forever.
     */
    public static function enqueue(string $sql, string $key, string $dialect, \PDO $pdo): void
    {
        if ($dialect !== Sql::POSTGRES) {
            return;
        }
        if (preg_match(self::READ_ONLY, $sql) !== 1 || preg_match(self::UNSAFE, $sql) === 1) {
            return;
        }
        if (!Sql::valueFree($sql)) {
            return;
        }
        if (isset(self::$attempted[$key])
            || count(self::$pending) >= self::MAX_PENDING
            || count(self::$plans) >= self::MAX_PLANS) {
            return;
        }

        self::$attempted[$key] = true;
        self::$pending[$key] = ['sql' => $sql, 'pdo' => $pdo];
    }

    /**
     * Explain what is queued. Called from the flush, never from a request.
     *
     * Failures are swallowed deliberately and completely. None of the ordinary
     * reasons this fails is the application's problem, and an observability
     * agent that turns a planning quirk into a runtime error has done far more
     * harm than the signal is worth.
     */
    public static function capturePending(): void
    {
        $queued = self::$pending;
        self::$pending = [];

        foreach ($queued as $key => $entry) {
            try {
                $statement = $entry['pdo']->query('explain (generic_plan, format json) ' . $entry['sql']);
                if ($statement === false) {
                    continue;
                }
                $row = $statement->fetch(\PDO::FETCH_NUM);
                $raw = is_array($row) ? ($row[0] ?? null) : null;
                if (!is_string($raw)) {
                    continue;
                }
                $shaped = self::shape(json_decode($raw, true));
                if ($shaped === null) {
                    continue;
                }
                if (count(self::$plans) < self::MAX_PLANS) {
                    $shaped['key'] = self::planKey($shaped['shape']);
                    self::$plans[$key] = $shaped;
                }
            } catch (\Throwable) {
                continue;
            }
        }
    }

    /**
     * Reduce an EXPLAIN (FORMAT JSON) result to a stable, comparable shape.
     *
     * A plan as Postgres emits it is mostly numbers — startup cost, total cost,
     * row estimate, width, loops — and every one of those moves whenever the
     * statistics move, which is after every autovacuum. A detector comparing
     * plans literally would fire constantly and mean nothing. What matters is
     * structural and changes rarely: this query used to use an index and now
     * scans the table.
     *
     * @return array<string, mixed>|null
     */
    public static function shape(mixed $explain): ?array
    {
        $root = null;
        if (is_array($explain)) {
            $root = $explain['Plan'] ?? ($explain[0]['Plan'] ?? null);
        }
        if (!is_array($root)) {
            return null;
        }

        $nodes = 0;
        $scans = [];

        $walk = function (array $node, int $depth) use (&$walk, &$nodes, &$scans): ?array {
            if ($depth > self::MAX_DEPTH || $nodes >= self::MAX_NODES) {
                return null;
            }
            $nodes++;

            $out = [];
            foreach (self::STRUCTURAL as $field) {
                if (isset($node[$field])) {
                    $out[$field] = $node[$field];
                }
            }

            $type = $node['Node Type'] ?? null;
            if (is_string($type) && str_contains($type, 'Scan') && isset($node['Relation Name'])) {
                $scans[] = isset($node['Index Name'])
                    ? "{$type} {$node['Relation Name']} using {$node['Index Name']}"
                    : "{$type} {$node['Relation Name']}";
            }

            // The presence of a filter is structural; its contents are not.
            if (isset($node['Filter'])) {
                $out['Filtered'] = true;
            }
            if (isset($node['Index Cond'])) {
                $out['IndexCond'] = true;
            }
            if (isset($node['Hash Cond'])) {
                $out['HashCond'] = true;
            }

            $children = [];
            foreach ($node['Plans'] ?? [] as $child) {
                $shaped = is_array($child) ? $walk($child, $depth + 1) : null;
                if ($shaped !== null) {
                    $children[] = $shaped;
                }
            }
            if ($children !== []) {
                $out['Plans'] = $children;
            }

            return $out;
        };

        $shape = $walk($root, 0);
        if ($shape === null) {
            return null;
        }

        // Two lists, because they answer different questions. `scans` is every
        // scan node, which is what a person reading a summary wants. `seqScans`
        // is only the sequential ones — the reads with no index — which is what
        // the detector reports as a defect.
        return [
            'shape' => $shape,
            'summary' => self::summarize($shape),
            'scans' => $scans,
            'seqScans' => self::sequentialScans($shape),
        ];
    }

    /**
     * A stable identity for a shape, so "did the plan change" is one string
     * comparison. Key order is normalised on the way in: Postgres emits fields
     * consistently today, but a shape whose identity depended on that would
     * report a change the first time a minor release reordered them.
     *
     * @param array<string, mixed> $shape
     */
    public static function planKey(array $shape): string
    {
        return json_encode(self::canonical($shape), JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * @param array<string, mixed> $shape
     * @return string[]
     */
    public static function sequentialScans(array $shape): array
    {
        $found = [];
        $walk = function (array $node) use (&$walk, &$found): void {
            if (($node['Node Type'] ?? null) === 'Seq Scan' && isset($node['Relation Name'])) {
                $found[] = $node['Relation Name'];
            }
            foreach ($node['Plans'] ?? [] as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($shape);

        return $found;
    }

    public static function reset(): void
    {
        self::$plans = [];
        self::$pending = [];
        self::$attempted = [];
    }

    /**
     * A one-line description, for a feed card with no room for a tree. It reads
     * outside-in — the top node is what the query *is*, the scans are what it
     * costs — because "Aggregate over Seq Scan on orders" is the sentence a
     * person would say out loud.
     *
     * @param array<string, mixed> $shape
     */
    private static function summarize(array $shape): string
    {
        $top = $shape['Node Type'] ?? 'Plan';
        $scans = [];
        $collect = function (array $node) use (&$collect, &$scans): void {
            $type = $node['Node Type'] ?? null;
            if (is_string($type) && str_contains($type, 'Scan') && isset($node['Relation Name'])) {
                $scans[] = str_replace(' Scan', '', $type) . " on {$node['Relation Name']}";
            }
            foreach ($node['Plans'] ?? [] as $child) {
                if (is_array($child)) {
                    $collect($child);
                }
            }
        };
        $collect($shape);

        return $scans === [] ? (string) $top : $top . ' over ' . implode(', ', array_unique($scans));
    }

    private static function canonical(mixed $node): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        if (array_is_list($node)) {
            return array_map(self::canonical(...), $node);
        }
        ksort($node);

        return array_map(self::canonical(...), $node);
    }
}
