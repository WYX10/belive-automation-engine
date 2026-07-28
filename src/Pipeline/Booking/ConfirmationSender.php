<?php

declare(strict_types=1);

namespace App\Pipeline\Booking;

use App\AI\Memory\EpisodicLogger;
use App\Integrations\WhatsApp\WhatsAppClient;
use App\Models\Booking;
use App\Models\Lead;
use App\Models\Room;
use App\Models\Staff;
use App\Pipeline\Referral\ReferralRewardWebhook;

/**
 * Intelligent booking, part 3: sends the WhatsApp confirmation "receipt",
 * marks it sent, and fires the Refer & Earn reward hook — the moment a
 * referred lead's booking confirms, their referrer is credited.
 */
final class ConfirmationSender
{
    public static function send(
        array $booking,
        string $modelUsed,
        ?WhatsAppClient $wa = null,
        ?WhatsAppClient $rewardNotificationClient = null
    ): void
    {
        $wa ??= new WhatsAppClient();

        $lead = Lead::find((int) $booking['lead_id']);
        if ($lead === null) {
            return;
        }

        $room = $booking['room_id'] !== null ? Room::find((int) $booking['room_id']) : null;
        $when = date('l, j M Y \a\t g:ia', strtotime($booking['viewing_datetime']));

        $modeLine = ViewingMode::label($booking['viewing_mode'] ?? null);
        $closing = ($booking['viewing_mode'] ?? null) === ViewingMode::VIDEO_CALL
            ? 'No travel needed — just pick up when we call. Need to reschedule? Reply here anytime.'
            : 'Just bring yourself — we handle the rest. Need to reschedule? Reply here anytime.';

        $agent = ($booking['staff_id'] ?? null) !== null ? Staff::find((int) $booking['staff_id']) : null;

        $lines = array_values(array_filter([
            '✅ Viewing confirmed!',
            $room !== null
                ? "🏠 {$room['name']} — {$room['location']} ({$room['room_type']} room)"
                : '🏠 BeLive room viewing',
            "🗓 $when",
            $modeLine,
            $agent !== null ? "👤 Your host: {$agent['name']}" : null,
            '',
            $closing,
            '',
            '🔑 Your tenant portal is open — agreement, move-in log, electric bill and rewards, all in one place:',
            self::tenantPortalLink(),
            'Log in with this same WhatsApp number.',
        ], fn ($line) => $line !== null));

        // Reward attribution is durable booking state. Record it before either
        // outbound WhatsApp send can fail; a later retry remains idempotent.
        ReferralRewardWebhook::onBookingConfirmed(
            (int) $lead['id'],
            $room !== null ? (int) $room['id'] : null,
            $rewardNotificationClient
        );

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

    /**
     * The tenant portal sign-in page. The confirmation is the one message we
     * know reaches the number that IS the portal identity, so it is also where
     * the login link belongs — no separate "how do I get in?" round trip.
     */
    private static function tenantPortalLink(): string
    {
        return rtrim($_ENV['APP_URL'] ?? 'http://localhost:8080', '/') . '/tenant/login';
    }
}
