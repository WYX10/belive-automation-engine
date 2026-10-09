<?php

declare(strict_types=1);

namespace App\AI\Memory;

final class LessonIdentity
{
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[.!。！]+$/u', '', $text);
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    public static function hash(string $context, string $type, string $text, ?string $key = null): string
    {
        return hash('sha256', self::normalize($context) . "\0" . $type . "\0" . ($key ?? self::normalize($text)));
    }
}
