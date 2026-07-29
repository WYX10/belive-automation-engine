<?php

declare(strict_types=1);

namespace App\Integrations\WhatsApp;

/**
 * One place that turns whatever a human typed into the digits-only,
 * country-coded form Meta expects (60123456789).
 *
 * This matters more than it looks: the Cloud API happily returns 200 and a
 * message id for a plausible-but-wrong number, then never delivers. A visitor
 * typing their number the Malaysian way — 012-345 6789 — would silently get
 * nothing, while the dashboard showed the reply as sent.
 */
final class PhoneNumber
{
    /** Malaysia — every other country code must be typed in full. */
    private const DEFAULT_COUNTRY = '60';

    public static function normalize(string $raw): string
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        if ($digits === '') {
            return '';
        }

        // 0060... — international access code typed instead of '+'.
        if (str_starts_with($digits, '00')) {
            return substr($digits, 2);
        }

        // 0123456789 — the local way to write a Malaysian mobile.
        if (str_starts_with($digits, '0')) {
            return self::DEFAULT_COUNTRY . substr($digits, 1);
        }

        // 123456789 — local number with the trunk zero dropped. Malaysian
        // mobiles are 1 + 8-9 digits; 1 + 10 digits is a US number, left alone.
        if (str_starts_with($digits, '1') && strlen($digits) <= 10) {
            return self::DEFAULT_COUNTRY . $digits;
        }

        return $digits;
    }

    /** True for something that can plausibly be an E.164 subscriber number. */
    public static function isValid(string $normalized): bool
    {
        return preg_match('/^[1-9]\d{9,14}$/', $normalized) === 1;
    }
}
