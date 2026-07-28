<?php

declare(strict_types=1);

namespace App\Integrations\WhatsApp;

use App\Core\Settings;

/**
 * One place that knows how to build a "message Eve on WhatsApp" link.
 *
 * Social auto-replies, social post captions and anything else that hands a
 * customer a way back to us all point at the same number, so the number lives
 * in one setting (social_wa_number, falling back to EVE_WA_NUMBER) instead of
 * being rebuilt per caller.
 */
final class WhatsAppLink
{
    /** Digits only — Meta rejects a wa.me path with spaces or a leading +. */
    public static function number(): string
    {
        return preg_replace('/\D/', '', Settings::get('social_wa_number', $_ENV['EVE_WA_NUMBER'] ?? ''));
    }

    /**
     * A wa.me link that opens the chat with $prefill already typed. With no
     * number configured the portal's shared fallback still reaches us, but it
     * cannot carry a prefill — so anything riding in one (attribution tokens,
     * the room being asked about) is lost.
     */
    public static function to(string $prefill = ''): string
    {
        $number = self::number();
        if ($number === '') {
            return 'https://wa.link/hg32ho';
        }

        $prefill = trim($prefill);

        return 'https://wa.me/' . $number . ($prefill === '' ? '' : '?text=' . rawurlencode($prefill));
    }
}
