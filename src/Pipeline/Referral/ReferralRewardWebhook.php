<?php

declare(strict_types=1);

namespace App\Pipeline\Referral;

use App\AI\Memory\EpisodicLogger;
use App\Integrations\WhatsApp\WhatsAppClient;
use App\Models\Lead;
use App\Models\Referral;
use App\Models\Room;

/**
 * Reward crediting: fired by the booking pipeline when a referred lead's
 * booking is confirmed. Credits the referrer using the booked room's owner-
 * configured reward and tells them on WhatsApp.
 */
final class ReferralRewardWebhook
{
    /** @return array|null the credited referral row, or null if none applies */
    public static function onBookingConfirmed(
        int $bookedLeadId,
        ?int $roomId,
        ?WhatsAppClient $notificationClient = null
    ): ?array
    {
        $room = $roomId !== null ? Room::find($roomId) : null;
        $rewardRoomId = $room !== null ? (int) $room['id'] : null;
        $points = $room !== null
            ? (int) $room['referral_reward_points']
            : REFERRAL_REWARD_POINTS;
        $credited = Referral::creditForReferredLead($bookedLeadId, $points, $rewardRoomId);
        if ($credited === null) {
            return null;
        }

        $referrer = Lead::find((int) $credited['referring_lead_id']);

        EpisodicLogger::activity(
            'referral_reward_credited',
            'lead_gen',
            null,
            (int) $credited['referring_lead_id'],
            sprintf(
                'code %s: %d points (referred lead #%d booked room #%s)',
                $credited['referral_code'],
                $points,
                $bookedLeadId,
                $rewardRoomId ?? 'none'
            )
        );

        // Tell the referrer — pseudo-handles (fb:/ig:) can't receive WhatsApp.
        if ($referrer !== null && preg_match('/^\d+$/', $referrer['wa_phone'])) {
            $name = $referrer['name'] ? " {$referrer['name']}" : '';
            try {
                ($notificationClient ?? new WhatsAppClient())->sendText(
                    $referrer['wa_phone'],
                    "🎉 Nice one{$name}! Your friend just booked a viewing through your BeLive link — "
                    . $points . ' reward points are on your account. Share again anytime: more friends, more rewards.'
                );
            } catch (\Throwable $e) {
                // Notification is best-effort: never block the referred friend's
                // booking confirmation after the ledger credit is durable.
                try {
                    EpisodicLogger::activity(
                        'referral_reward_notification_failed',
                        'lead_gen',
                        null,
                        (int) $credited['referring_lead_id'],
                        mb_substr($e->getMessage(), 0, 300)
                    );
                } catch (\Throwable) {
                    // Preserve the booking flow if failure logging is unavailable.
                }
            }
        }

        return $credited;
    }
}
