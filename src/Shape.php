<?php

declare(strict_types=1);

namespace Sixty;

/**
 * Argument shape: what a query *is*, with none of what it is about.
 *
 * SECURITY BOUNDARY, and the document-database counterpart to Sixty\Sql.
 *
 * For SQL, identity is the statement with its literals stripped — a string with
 * things taken out of it. That approach has no analogue here. A Mongo filter is
 * a tree in which the values sit *beside* the keys, arbitrarily deep, so there
 * is no text to lex and nothing to remove: the values are structurally
 * interleaved with the only part we are allowed to keep.
 *
 * So this walk never removes anything. It emits keys, and a value has no path
 * to the output at all — not a redaction that could miss a case, but a
 * construction in which the unsafe half is unreachable. If you are tempted to
 * add "just the value, when it is a small integer, for readability": don't.
 */
final class Shape
{
    public const MAX_DEPTH = 6;
    public const MAX_KEYS = 24;
    public const MAX_STAGES = 12;

    /**
     * The shape of one argument tree: `filter{createdAt{$gte},status}`.
     *
     * Keys are sorted, because two call sites writing the same query with the
     * keys in a different order are the same query, and insertion order would
     * make them two operations that never accumulate a shared history.
     *
     * Only an array or a stdClass-like document is structure. Everything else
     * is a *value*, however many properties it carries — a MongoDB\BSON\ObjectId
     * walked as a structure would put its internals into the identity of every
     * query that filters by `_id`, which is most of them.
     */
    public static function of(mixed $value, int $depth = 0): string
    {
        if ($depth > self::MAX_DEPTH) {
            return '';
        }

        if (is_array($value) && array_is_list($value)) {
            // A list contributes the shape of its first element, never its
            // length. `['$in' => [...]]` with three ids and with three thousand
            // is one operation — the same bargain SQL normalization strikes
            // when it folds `in (?, ?, ?)` down to `in (?)`.
            return $value === [] ? '' : self::of($value[0], $depth + 1);
        }

        $entries = null;
        if (is_array($value)) {
            $entries = $value;
        } elseif ($value instanceof \MongoDB\BSON\Document || $value instanceof \MongoDB\BSON\PackedArray) {
            $entries = (array) $value->toPHP();
        } elseif ($value instanceof \stdClass) {
            $entries = (array) $value;
        }

        if ($entries === null || $entries === []) {
            return '';
        }
        if (array_is_list($entries)) {
            return self::of($entries[0], $depth + 1);
        }

        $keys = array_keys($entries);
        sort($keys);
        $keys = array_slice($keys, 0, self::MAX_KEYS);

        $parts = [];
        foreach ($keys as $key) {
            $inner = self::of($entries[$key], $depth + 1);
            $parts[] = $inner === '' ? (string) $key : "{$key}{{$inner}}";
        }

        return implode(',', $parts);
    }

    /**
     * The shape of an *ordered* structure, where every element matters.
     *
     * An aggregation pipeline is a list whose entries are deliberately
     * different from one another — `[{$match}, {$group}, {$sort}]` — so it is
     * structure, not data. Taking only the first element the way `of()` does
     * would collapse every pipeline in the application to the shape of whatever
     * it happened to start with, and `$match → $group` and
     * `$match → $lookup → $unwind` would be one operation. That is the
     * difference between a working query and the N+1 that replaced it.
     */
    public static function ofSequence(mixed $values): string
    {
        if ($values instanceof \MongoDB\BSON\PackedArray) {
            $values = $values->toPHP();
        }
        if (!is_array($values) || $values === []) {
            return '';
        }
        $values = array_values($values);

        $stages = [];
        foreach (array_slice($values, 0, self::MAX_STAGES) as $stage) {
            $inner = self::of($stage, 1);
            $stages[] = $inner === '' ? '[]' : "[{$inner}]";
        }
        if (count($values) > self::MAX_STAGES) {
            $stages[] = '[…]';
        }

        return implode('', $stages);
    }
}
