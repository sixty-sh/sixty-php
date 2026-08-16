<?php

declare(strict_types=1);

namespace Sixty;

/**
 * The privacy boundary for text the agent did not construct.
 *
 * SQL is lexed and Mongo filters are walked, so neither can carry a value out.
 * An *exception message* is different in kind: it is prose a driver or an
 * application composed, and the useful half and the dangerous half are the same
 * half. `Cannot read property 'name' of null` is worth keeping; `Duplicate entry
 * 'alice@example.com' for key 'users_email_unique'` is a leak.
 *
 * This is a port of packages/core/src/redact.js — the same rules the browser and
 * React Native agents apply — because two implementations of "what counts as a
 * value" means the weaker one sets the real guarantee the first time somebody
 * patches a pattern into one and not the other.
 *
 * ── The one judgement call ───────────────────────────────────────────────
 *
 * A quoted string survives only if it looks like a program identifier: short,
 * no spaces, no punctuation beyond `_` and `$`. A property name survives. An
 * address, a sentence, a token, a filename and a value do not. The heuristic is
 * deliberately biased towards redacting, because a lost debugging hint is
 * recoverable and a leaked address is not.
 */
final class Redact
{
    private const IDENTIFIER = '/\A[A-Za-z_$][\w$]{0,39}\z/';

    public static function message(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return null;
        }

        $out = substr($message, 0, 500);

        // Highest-risk patterns first, so a token inside quotes is already gone
        // by the time the identifier test would have had a chance to keep it.
        $out = preg_replace([
            '/[\w.+-]+@[\w-]+\.[\w.-]+/',
            '/\b(?:https?|wss?|file|data|blob):\S+/i',
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i',
            '/\beyJ[A-Za-z0-9_-]{8,}/',
            '/\b[0-9a-f]{16,}\b/i',
            '/\b[A-Za-z0-9+\/]{24,}={0,2}\b/',
            '/\+?\d[\d\s().-]{8,}\d/',
        ], [
            '<email>', '<url>', '<uuid>', '<jwt>', '<hex>', '<blob>', '<number>',
        ], $out) ?? $out;

        $out = preg_replace_callback(
            '/([\'"`])(.*?)\1/s',
            static fn (array $m): string => preg_match(self::IDENTIFIER, $m[2]) === 1
                ? "'{$m[2]}'"
                : "'<value>'",
            $out,
        ) ?? $out;

        // Remaining bare digit runs. Two or more, so "Cannot read property 0"
        // keeps its index while an account number does not survive.
        $out = preg_replace('/\b\d{2,}\b/', '<n>', $out) ?? $out;
        $out = trim((string) preg_replace('/\s+/', ' ', $out));

        return $out === '' ? null : substr($out, 0, 200);
    }
}
