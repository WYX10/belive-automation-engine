<?php

declare(strict_types=1);

namespace App\AI\Skills;

/**
 * Tiny shared helpers for the four skills: robust JSON extraction from model
 * output and a timing wrapper. No business logic.
 */
final class SkillSupport
{
    /** Pull the first JSON object out of a model reply (handles code fences). */
    public static function extractJson(string $text): ?array
    {
        // Fast path: the whole reply is JSON.
        $decoded = json_decode(trim($text), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Strip ```json fences, then take the outermost {...} span.
        $clean = preg_replace('/```(?:json)?/i', '', $text);
        $start = strpos($clean, '{');
        $end = strrpos($clean, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($clean, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return array{0: mixed, 1: int} [result, elapsed ms] */
    public static function timed(callable $fn): array
    {
        $started = hrtime(true);
        $result = $fn();

        return [$result, (int) ((hrtime(true) - $started) / 1_000_000)];
    }
}
