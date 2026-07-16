<?php

declare(strict_types=1);

namespace App\Pipeline\Referral;

use App\AI\Memory\EpisodicLogger;
use App\Integrations\WhatsApp\WhatsAppClient;
use App\Models\Lead;
use App\Models\Referral;

/**
 * Reward crediting: fired by the booking pipeline when a referred lead's
 * booking is confirmed. Credits the referrer a fixed number of points
 * (competition scope — no tiers) and tells them on WhatsApp.
 */
final class ReferralRewardWebhook
{
    /** @return array|null the credited referral row, or null if none applies */
    public static function onBookingConfirmed(int $bookedLeadId): ?array
    {
        $credited = Referral::creditForReferredLead($bookedLeadId);
        if ($credited === null) {
            return null;
        }

        $referrer = Lead::find((int) $credited['referring_lead_id']);

        EpisodicLogger::activity(
            'referral_reward_credited',
            'lead_gen',
            null,
            (int) $credited['referring_lead_id'],
            sprintf('code %s: %d points (referred lead #%d booked)', $credited['referral_code'], REFERRAL_REWARD_POINTS, $bookedLeadId)
        );

        // Tell the referrer — pseudo-handles (fb:/ig:) can't receive WhatsApp.
        if ($referrer !== null && preg_match('/^\d+$/', $referrer['wa_phone'])) {
            $name = $referrer['name'] ? " {$referrer['name']}" : '';
            (new WhatsAppClient())->sendText(
                $referrer['wa_phone'],
                "🎉 Nice one{$name}! Your friend just booked a viewing through your BeLive link — "
                . REFERRAL_REWARD_POINTS . ' reward points are on your account. Share again anytime: more friends, more rewards.'
            );
        }

        return $credited;
    }
}
