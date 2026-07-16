<?php

declare(strict_types=1);

namespace App\Models;

/**
 * referrals — the Refer & Earn engine's ledger. One row per referral code;
 * referred_lead_id fills in when someone arrives through the link, and
 * reward_status flips to 'credited' when that referred lead books.
 */
final class Referral extends BaseModel
{
    protected const TABLE = 'referrals';

    public static function findByCode(string $code): ?array
    {
        return self::first(['referral_code' => $code]);
    }

    /** Existing share code for a lead, or a fresh unique one. */
    public static function codeFor(int $referringLeadId): string
    {
        $existing = self::first(['referring_lead_id' => $referringLeadId, 'referred_lead_id' => null]);
        if ($existing !== null) {
            return $existing['referral_code'];
        }

        do {
            $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        } while (self::findByCode($code) !== null);

        self::create([
            'referral_code'     => $code,
            'referring_lead_id' => $referringLeadId,
            'reward_status'     => 'pending',
            'reward_points'     => 0,
        ]);

        return $code;
    }

    /** Attach an arriving referred lead to the open code row. */
    public static function attachReferredLead(string $code, int $referredLeadId): bool
    {
        $row = self::findByCode($code);
        if ($row === null || $row['referred_lead_id'] !== null) {
            return false;
        }
        if ((int) $row['referring_lead_id'] === $referredLeadId) {
            return false; // no self-referrals
        }

        return self::update((int) $row['id'], ['referred_lead_id' => $referredLeadId]);
    }

    /** Credit the referrer once the referred lead completes a booking. */
    public static function creditForReferredLead(int $referredLeadId): ?array
    {
        $row = self::first(['referred_lead_id' => $referredLeadId, 'reward_status' => 'pending']);
        if ($row === null) {
            return null;
        }

        self::update((int) $row['id'], [
            'reward_status' => 'credited',
            'reward_points' => REFERRAL_REWARD_POINTS,
            'credited_at'   => date('Y-m-d H:i:s'),
        ]);

        return self::find((int) $row['id']);
    }
}
