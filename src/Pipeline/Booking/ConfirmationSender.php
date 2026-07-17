<?php

declare(strict_types=1);

namespace App\Pipeline\Booking;

use App\AI\Memory\EpisodicLogger;
use App\Integrations\WhatsApp\WhatsAppClient;
use App\Models\Booking;
use App\Models\Lead;
use App\Models\Room;
use App\Pipeline\Referral\ReferralRewardWebhook;

/**
 * Intelligent booking, part 3: sends the WhatsApp confirmation "receipt",
 * marks it sent, and fires the Refer & Earn reward hook — the moment a
 * referred lead's booking confirms, their referrer is credited.
 */
final class ConfirmationSender
{
    public static function send(array $booking, string $modelUsed, ?WhatsAppClient $wa = null): void
    {
        $wa ??= new WhatsAppClient();

        $lead = Lead::find((int) $booking['lead_id']);
        if ($lead === null) {
            return;
        }

        $room = $booking['room_id'] !== null ? Room::find((int) $booking['room_id']) : null;
        $when = date('l, j M Y \a\t g:ia', strtotime($booking['viewing_datetime']));

        $lines = [
            '✅ Viewing confirmed!',
            $room !== null
                ? "🏠 {$room['name']} — {$room['location']} ({$room['room_type']} room)"
                : '🏠 BeLive room viewing',
            "🗓 $when",
            '',
            'Just bring yourself — we handle the rest. Need to reschedule? Reply here anytime.',
        ];

        // Reward attribution is durable booking state. Record it before either
        // outbound WhatsApp send can fail; a later retry remains idempotent.
        ReferralRewardWebhook::onBookingConfirmed((int) $lead['id'], $room !== null ? (int) $room['id'] : null);

        $sent = $wa->sendText($lead['wa_phone'], implode("\n", $lines));
        Booking::update((int) $booking['id'], ['confirmation_sent' => 1]);

        EpisodicLogger::log([
            'lead_id'      => (int) $lead['id'],
            'phase'        => 'conversion',
            'skill'        => 'automate',
            'model_used'   => $modelUsed,
            'direction'    => 'outbound',
            'message_out'  => implode("\n", $lines),
            'message_kind' => 'booking_confirmation',
            'reasoning'    => 'Automated booking confirmation for booking #' . $booking['id']
                . ($sent['dry_run'] ? ' (dry-run: no WhatsApp credential configured)' : ''),
        ]);
    }
}
