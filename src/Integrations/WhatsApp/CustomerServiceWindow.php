<?php

declare(strict_types=1);

namespace App\Integrations\WhatsApp;

use App\Models\Interaction;

/**
 * WhatsApp's 24-hour customer service window.
 *
 * Meta only lets a business send a free-form message to someone who messaged
 * it in the last 24 hours. Outside that window a plain text send is refused —
 * sometimes with an HTTP 400 (error 131047), sometimes accepted with a 200 and
 * then quietly failed on the delivery status webhook. Either way the customer
 * gets nothing, which is exactly what a website enquiry looks like: the visitor
 * has never messaged us, so there is no window to reply into.
 *
 * The only two lawful ways to open a conversation are an approved message
 * template, or the customer messaging first. WhatsAppLink's wa.me deep link
 * does the second: they tap, WhatsApp opens with their enquiry prefilled, and
 * the moment they send it the window opens and Eve replies through the normal
 * inbound pipeline.
 */
final class CustomerServiceWindow
{
    public const HOURS = 24;

    /** True when this lead messaged us recently enough to reply freely. */
    public static function isOpenFor(int $leadId): bool
    {
        $minutes = Interaction::minutesSinceLastInbound($leadId);

        return $minutes !== null && $minutes < self::HOURS * 60;
    }
}
