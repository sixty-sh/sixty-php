<?php

declare(strict_types=1);

namespace Sixty;

/**
 * Where in your code did this operation come from.
 *
 * A finding you cannot locate in your own repository is much less actionable —
 * "select:orders returns 30,000 rows" is only useful once you know which file
 * writes that query. In a Laravel or Symfony app that file is almost never the
 * one that executes the statement: an ORM does, through a dozen frames of
 * builder, connection and driver. So the frames kept here are the
 * application's own, and the framework's are skipped.
 *
 * ── Why this is affordable ────────────────────────────────────────────────
 *
 * `debug_backtrace` costs microseconds, which is unacceptable per query. But a
 * call site is a property of the *operation*, not of the call: the same query
 * is issued from the same place every time. So the stack is captured on the
 * first sighting of a normalized statement and never again — and
 * DEBUG_BACKTRACE_IGNORE_ARGS is not optional, because the arguments are the
 * customer's data and this agent must never hold them.
 */
final class Stack
{
    private const MAX_FRAMES = 4;
    private const MAX_TRACKED = 2000; // matches the aggregator's operation cap
    private const DEPTH = 40;

    /**
     * Frames from the framework, the vendor directory and this agent are never
     * the answer — the caller wants their own code.
     */
    private const NOT_USER_CODE = '#[/\\\\]vendor[/\\\\]|[/\\\\]packages[/\\\\]php[/\\\\]src[/\\\\]#';

    private static string $root = '';
    /** @var array<string, true> */
    private static array $seen = [];

    public static function setRoot(string $root): void
    {
        self::$root = $root;
    }

    /**
     * @return array<int, array{file: string, line: int, fn: string}>|null
     *         frames, once per key, null every time after
     */
    public static function capture(string $key): ?array
    {
        if ($key === '' || isset(self::$seen[$key]) || count(self::$seen) >= self::MAX_TRACKED) {
            return null;
        }
        self::$seen[$key] = true;

        $frames = [];
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::DEPTH) as $frame) {
            $file = $frame['file'] ?? null;
            if ($file === null || preg_match(self::NOT_USER_CODE, $file) === 1) {
                continue;
            }
            $function = $frame['function'] ?? '';
            $class = $frame['class'] ?? '';
            $frames[] = [
                'file' => self::relativise($file),
                'line' => (int) ($frame['line'] ?? 0),
                'fn' => substr($class !== '' ? "{$class}::{$function}" : $function, 0, 80),
            ];
            if (count($frames) >= self::MAX_FRAMES) {
                break;
            }
        }

        return $frames === [] ? null : $frames;
    }

    /**
     * Repository-relative paths, so a frame is comparable across machines and
     * can be turned into a link into the commit that produced it. An absolute
     * deploy path is meaningless to a reader and discloses the layout of the
     * host it was built on.
     */
    public static function relativise(string $path): string
    {
        $prefix = self::$root;
        if ($prefix === '') {
            return $path;
        }
        if (!str_ends_with($prefix, '/')) {
            $prefix .= '/';
        }

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    public static function reset(): void
    {
        self::$seen = [];
    }
}
