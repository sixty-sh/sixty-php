<?php

declare(strict_types=1);

namespace Sixty;

/**
 * SQL and route normalization.
 *
 * SECURITY BOUNDARY. Everything in this file runs inside the customer's
 * process, before any byte leaves their network. Raw SQL text contains PII in
 * literals ('alice@example.com', SSNs, tokens). We never transmit raw SQL —
 * only the literal-free shape. If you are tempted to add a "send the original
 * for debugging" option: don't.
 *
 * Normalization also bounds cardinality, which is the other way systems like
 * this die. `where id = 1` and `where id = 99` must collapse to one operation
 * or the operations table grows with traffic instead of with the number of
 * queries the application contains.
 *
 * Ported from packages/core/src/sql.js and kept deliberately close to it. A
 * Laravel app and a Node service in the same organization must reduce the same
 * statement to the same shape, because that shape is what the collector hashes
 * into an operation's identity — two spellings would split one operation's
 * history in half at the moment somebody rewrites a service in the other
 * language.
 */
final class Sql
{
    public const POSTGRES = 'postgres';
    public const MYSQL = 'mysql';

    /**
     * A prepared statement is executed thousands of times with one text.
     * Normalization is a per-character lexer, so results are memoized against
     * the raw text — bounded, because a caller with unbounded distinct SQL is
     * exactly the case that would otherwise grow this array forever.
     */
    private const MAX_CACHED = 1000;

    /** @var array<string, array{0: string, 1: int}> */
    private static array $cache = [];
    /** @var array<string, string> */
    private static array $names = [];

    public static function normalize(string $sql, string $dialect = self::POSTGRES): string
    {
        return self::analyze($sql, $dialect)[0];
    }

    /**
     * Did this statement arrive with its values already out of it?
     *
     * A statement written with bind parameters (`where id = $1`) carries no
     * data; one written with literals (`where id = 42`) carries all of it. Only
     * the first kind may be handed back to the database in an EXPLAIN — see
     * Plans — and this is what decides which it is.
     *
     * Answered by the lexer rather than by a second pattern, because the
     * question is exactly "would normalization have removed anything", and the
     * only code that can answer that without disagreeing with itself is the
     * code that does the removing.
     */
    public static function valueFree(string $sql, string $dialect = self::POSTGRES): bool
    {
        return self::analyze($sql, $dialect)[1] === 0;
    }

    /** @return array{0: string, 1: int} the normalized text, and how many literals came out */
    private static function analyze(string $sql, string $dialect): array
    {
        $key = $dialect === self::MYSQL ? "mysql\0{$sql}" : $sql;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $result = self::lex($sql, $dialect);
        if (count(self::$cache) >= self::MAX_CACHED) {
            self::$cache = [];
        }
        self::$cache[$key] = $result;

        return $result;
    }

    /**
     * Strip literals, collapse whitespace, fold IN-lists.
     *
     * Deliberately a lexer, not a parser: it must never throw on a dialect
     * quirk, must be fast enough to run on every query, and its only job is
     * removing things — an unparseable statement still gets its literals
     * stripped, which is the property that matters.
     *
     * ── Why the dialect is a parameter and not a union of both rule sets ─────
     *
     * The two dialects disagree about a character rather than merely differing.
     * `"alice@example.com"` is a *quoted identifier* in Postgres — schema, safe
     * to keep — and in MySQL's default sql_mode the same bytes are a *string
     * literal*, which is exactly the PII this file exists to remove. Reading
     * MySQL with the Postgres rules would transmit it. Backticks are the mirror
     * image: MySQL's identifier quote, and not a quote at all in Postgres.
     *
     * The driver knows which one it is talking to (PDO::ATTR_DRIVER_NAME), so
     * that decides rather than a guess from the text.
     *
     * @return array{0: string, 1: int}
     */
    private static function lex(string $sql, string $dialect): array
    {
        $mysql = $dialect === self::MYSQL;
        $n = strlen($sql);
        $out = '';
        $i = 0;
        // Every literal removed, counted. A bind parameter is not a literal: it
        // was already a placeholder when it arrived.
        $literals = 0;

        while ($i < $n) {
            $c = $sql[$i];

            // --- line comment
            if ($c === '-' && ($sql[$i + 1] ?? '') === '-') {
                while ($i < $n && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            // --- MySQL line comment. `#` is not a comment introducer in Postgres.
            if ($mysql && $c === '#') {
                while ($i < $n && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            // --- block comment
            if ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
                $i += 2;
                while ($i < $n && !($sql[$i] === '*' && ($sql[$i + 1] ?? '') === '/')) {
                    $i++;
                }
                $i += 2;
                continue;
            }

            // --- single-quoted string (SQL escape is '')
            if ($c === "'") {
                $i++;
                while ($i < $n) {
                    // MySQL also honours backslash escapes by default, so
                    // `'it\'s'` does not end where a Postgres lexer thinks it
                    // does. Mis-finding the closing quote resumes lexing
                    // *inside* a literal, and the tail of somebody's data is
                    // then emitted as if it were SQL.
                    if ($mysql && $sql[$i] === '\\') {
                        $i += 2;
                        continue;
                    }
                    if ($sql[$i] === "'" && ($sql[$i + 1] ?? '') === "'") {
                        $i += 2;
                        continue;
                    }
                    if ($sql[$i] === "'") {
                        $i++;
                        break;
                    }
                    $i++;
                }
                $out .= '?';
                $literals++;
                continue;
            }

            // --- MySQL double-quoted string. Postgres reads these as
            //     identifiers and keeps them; here they are data.
            if ($mysql && $c === '"') {
                $i++;
                while ($i < $n) {
                    if ($sql[$i] === '\\') {
                        $i += 2;
                        continue;
                    }
                    if ($sql[$i] === '"' && ($sql[$i + 1] ?? '') === '"') {
                        $i += 2;
                        continue;
                    }
                    if ($sql[$i] === '"') {
                        $i++;
                        break;
                    }
                    $i++;
                }
                $out .= '?';
                $literals++;
                continue;
            }

            // --- MySQL backtick identifier: preserved, it is schema, not data
            if ($mysql && $c === '`') {
                $out .= $c;
                $i++;
                while ($i < $n) {
                    $out .= $sql[$i];
                    if ($sql[$i] === '`' && ($sql[$i + 1] ?? '') !== '`') {
                        $i++;
                        break;
                    }
                    if ($sql[$i] === '`' && ($sql[$i + 1] ?? '') === '`') {
                        $out .= $sql[$i + 1];
                        $i += 2;
                        continue;
                    }
                    $i++;
                }
                continue;
            }

            // --- dollar-quoted string ($tag$ ... $tag$), and $1 placeholders.
            //     Postgres only: `$` is a legal identifier character in MySQL.
            if (!$mysql && $c === '$') {
                $rest = substr($sql, $i);
                if (preg_match('/\A\$([A-Za-z_]\w*)?\$/', $rest, $m) === 1) {
                    $tag = $m[0];
                    $found = strpos($sql, $tag, $i + strlen($tag));
                    $i = $found === false ? $n : $found + strlen($tag);
                    $out .= '?';
                    $literals++;
                    continue;
                }
                if (preg_match('/\A\$\d+/', $rest, $p) === 1) {
                    // Already a placeholder. Normalized to one symbol so a
                    // difference in parameter *count* does not fragment
                    // identity.
                    $out .= '?';
                    $i += strlen($p[0]);
                    continue;
                }
            }

            // --- double-quoted identifier: preserved, it is schema, not data
            if ($c === '"') {
                $out .= $c;
                $i++;
                while ($i < $n) {
                    $out .= $sql[$i];
                    if ($sql[$i] === '"' && ($sql[$i + 1] ?? '') !== '"') {
                        $i++;
                        break;
                    }
                    if ($sql[$i] === '"' && ($sql[$i + 1] ?? '') === '"') {
                        $out .= $sql[$i + 1];
                        $i += 2;
                        continue;
                    }
                    $i++;
                }
                continue;
            }

            // --- numeric literal (not part of an identifier like col2)
            $previous = $i === 0 ? ' ' : $sql[$i - 1];
            if ($c >= '0' && $c <= '9' && preg_match('/[A-Za-z_$."]/', $previous) !== 1) {
                while ($i < $n && preg_match('/[0-9.eE+\-xa-fA-F]/', $sql[$i]) === 1) {
                    // stop at an operator that merely follows the number
                    if (preg_match('/[+\-]/', $sql[$i]) === 1
                        && preg_match('/[eE]/', $sql[$i - 1]) !== 1) {
                        break;
                    }
                    $i++;
                }
                $out .= '?';
                $literals++;
                continue;
            }

            // --- whitespace run
            if (preg_match('/\s/', $c) === 1) {
                $out .= ' ';
                while ($i < $n && preg_match('/\s/', $sql[$i]) === 1) {
                    $i++;
                }
                continue;
            }

            $out .= $c;
            $i++;
        }

        // fold IN (?, ?, ?) -> IN (?) so batch size does not fragment identity
        $out = preg_replace_callback(
            '/\b(?:in|IN|In)\s*\(\s*\?(?:\s*,\s*\?)+\s*\)/',
            static fn (array $m): string => substr($m[0], 0, (int) strpos($m[0], '(')) . '(?)',
            $out
        ) ?? $out;
        // fold multi-row VALUES (?),(?) -> VALUES (?)
        $out = preg_replace(
            '/\bvalues\s*(\(\s*\?(?:\s*,\s*\?)*\s*\))(?:\s*,\s*\(\s*\?(?:\s*,\s*\?)*\s*\))+/i',
            'values $1',
            $out
        ) ?? $out;
        $out = preg_replace('/\s+/', ' ', $out) ?? $out;
        $out = preg_replace('/\s*([(),;])\s*/', '$1', $out) ?? $out;

        return [trim($out), $literals];
    }

    private const VERB = '/\A\s*(select|insert|update|delete|with|begin|commit|rollback|create|alter'
        . '|drop|truncate|copy|explain|set|show|savepoint|release|listen|notify)\b/i';

    /**
     * The quote is optional and may be either dialect's, so `orders` and
     * `` `orders` `` produce one label rather than two spellings of it.
     */
    private const IDENT = '(["`]?[A-Za-z_][\w$]*["`]?\.)?["`]?([A-Za-z_][\w$]*)["`]?';

    /** @var array<string, string[]> which keyword introduces the relation that names the statement */
    private const BY_VERB = [
        'insert' => ['\binto\s+'],
        'update' => ['\bupdate\s+'],
        'delete' => ['\bdelete\s+from\s+', '\bfrom\s+'],
        'select' => ['\bfrom\s+', '\bjoin\s+'],
        'with' => ['\bfrom\s+', '\bjoin\s+'],
    ];

    /**
     * Short display name for a SQL operation: "select:orders", "insert:users".
     * The collector derives its own from the normalized text — this is what the
     * span carries so a trace is readable before it ever leaves the process.
     *
     * Memoized against the normalized statement: naming walks the statement
     * looking for the relation it touches, and the same few hundred statements
     * repeat forever. In the Ruby agent this one change took a query's
     * bookkeeping from twenty-five microseconds to five.
     */
    public static function operationName(string $normalized): string
    {
        if (isset(self::$names[$normalized])) {
            return self::$names[$normalized];
        }

        $name = self::computeOperationName($normalized);
        if (count(self::$names) >= self::MAX_CACHED) {
            self::$names = [];
        }

        return self::$names[$normalized] = $name;
    }

    private static function computeOperationName(string $normalized): string
    {
        [$body, $cteNames] = self::splitCtes($normalized);

        $verb = 'query';
        if (preg_match(self::VERB, $body, $m) === 1 || preg_match(self::VERB, $normalized, $m) === 1) {
            $verb = strtolower($m[1]);
        }

        // Resolved against the *main* statement, not the first common table
        // expression: otherwise every `with d as (select ... from unnest(...))`
        // collapses to the same unreadable label.
        $table = self::relationFor($verb, $body, $cteNames)
            ?? self::relationFor($verb, $normalized, $cteNames)
            ?? self::firstRelation($normalized, $cteNames);

        return $table !== null ? "{$verb}:{$table}" : $verb;
    }

    /** @param array<string, true> $cteNames */
    private static function relationFor(string $verb, string $sql, array $cteNames): ?string
    {
        foreach (self::BY_VERB[$verb] ?? self::BY_VERB['select'] as $lead) {
            if (preg_match_all('/' . $lead . self::IDENT . '/i', $sql, $matches, PREG_SET_ORDER) === false) {
                continue;
            }
            foreach ($matches as $match) {
                $name = $match[2] ?? '';
                // A CTE alias names nothing the reader can go and look at.
                if ($name !== '' && !isset($cteNames[strtolower($name)])) {
                    return $name;
                }
            }
        }

        return null;
    }

    /** @param array<string, true> $cteNames */
    private static function firstRelation(string $sql, array $cteNames): ?string
    {
        $pattern = '/\b(?:from|into|update|join)\s+' . self::IDENT . '/i';
        if (preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER) === false) {
            return null;
        }
        foreach ($matches as $match) {
            $name = $match[2] ?? '';
            if ($name !== '' && !isset($cteNames[strtolower($name)])) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Split a statement into its CTE names and the statement that follows them.
     * Paren-aware: a CTE body contains commas and parentheses, and a regex that
     * ignores nesting stops in the wrong place.
     *
     * @return array{0: string, 1: array<string, true>}
     */
    private static function splitCtes(string $sql): array
    {
        $names = [];
        if (preg_match('/\A\s*with\b/i', $sql) !== 1) {
            return [$sql, $names];
        }

        if (preg_match('/\bwith\b/i', $sql, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return [$sql, $names];
        }
        $i = $m[0][1] + 4;

        for (;;) {
            $rest = substr($sql, $i);
            $named = preg_match('/\A\s*(?:recursive\s+)?["`]?([A-Za-z_][\w$]*)["`]?/i', $rest, $nameMatch) === 1;
            $open = strpos($sql, '(', $i);
            if ($open === false) {
                return [$sql, $names];
            }
            if ($named) {
                $names[strtolower($nameMatch[1])] = true;
            }

            $depth = 0;
            $j = $open;
            $length = strlen($sql);
            for (; $j < $length; $j++) {
                if ($sql[$j] === '(') {
                    $depth++;
                } elseif ($sql[$j] === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $j++;
                        break;
                    }
                }
            }
            if ($depth !== 0) {
                return [$sql, $names]; // unbalanced; do not guess
            }

            $tail = substr($sql, $j);
            if (preg_match('/\A\s*,/', $tail, $comma) !== 1) {
                return [ltrim($tail), $names];
            }
            $i = $j + strlen($comma[0]);
        }
    }

    private const UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    /**
     * Template an HTTP path so /users/42 and /users/43 are one operation. Used
     * only when the framework does not hand us a route pattern; a real Laravel
     * or Symfony route always wins over this heuristic.
     */
    public static function templatePath(string $path): string
    {
        $path = explode('?', $path, 2)[0];

        $segments = array_map(static function (string $segment): string {
            if ($segment === '') {
                return $segment;
            }
            if (preg_match('/\A\d+\z/', $segment) === 1) {
                return ':id';
            }
            if (preg_match(self::UUID, $segment) === 1) {
                return ':uuid';
            }
            if (preg_match('/\A[0-9a-f]{24,}\z/i', $segment) === 1) {
                return ':hash';
            }
            // A colon inside a path segment is almost never part of a route:
            // routes are named with words. It is, reliably, a delimiter inside
            // an id.
            if (str_contains($segment, ':') && !str_starts_with($segment, ':')) {
                return ':id';
            }
            // long, high-entropy, mixed-case segments are almost always ids
            if (strlen($segment) > 24
                && preg_match('/\d/', $segment) === 1
                && preg_match('/[A-Za-z]/', $segment) === 1) {
                return ':id';
            }

            return $segment;
        }, explode('/', $path));

        $templated = implode('/', $segments);

        return $templated === '' ? '/' : $templated;
    }
}
