<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDOException;

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

    /** @return array<int, array<string, mixed>> newest referral attempts first */
    public static function forReferrer(int $referringLeadId): array
    {
        return Database::run(
            'SELECT ref.id, ref.referral_code, ref.referring_lead_id, ref.referred_lead_id,
                    ref.reward_room_id, ref.reward_points, ref.reward_status, ref.clicks,
                    ref.credited_at, ref.created_at,
                    COALESCE(ref.reward_room_name, r.name) AS reward_room_name,
                    COALESCE(ref.reward_property_name, r.property_name) AS reward_property_name
             FROM referrals ref
             LEFT JOIN rooms r ON r.id = ref.reward_room_id
             WHERE ref.referring_lead_id = ?
             ORDER BY ref.id DESC',
            [$referringLeadId]
        )->fetchAll();
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

        try {
            return Database::run(
                'UPDATE referrals
                 SET referred_lead_id = ?
                 WHERE id = ? AND referred_lead_id IS NULL',
                [$referredLeadId, (int) $row['id']]
            )->rowCount() === 1;
        } catch (PDOException $e) {
            // uq_referral_referred_lead guarantees one immutable attribution per friend,
            // including when two referral links arrive concurrently.
            if ($e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }
    }

    /** Credit the referrer once the referred lead completes a booking. */
    public static function creditForReferredLead(
        int $referredLeadId,
        int $rewardPoints = REFERRAL_REWARD_POINTS,
        ?int $roomId = null
    ): ?array
    {
        if ($rewardPoints < 0 || $rewardPoints > 1000) {
            throw new \InvalidArgumentException('Referral reward points must be between 0 and 1,000.');
        }
        $row = self::first(['referred_lead_id' => $referredLeadId, 'reward_status' => 'pending']);
        if ($row === null) {
            return null;
        }

        $room = $roomId !== null ? Room::find($roomId) : null;
        $rewardRoomId = $room !== null ? (int) $room['id'] : null;
        $roomName = $room !== null ? (string) $room['name'] : null;
        $propertyName = $room !== null ? (string) $room['property_name'] : null;

        $updated = Database::run(
            "UPDATE referrals
             SET reward_status = 'credited', reward_points = ?, reward_room_id = ?,
                 reward_room_name = ?, reward_property_name = ?, credited_at = ?
             WHERE id = ? AND reward_status = 'pending'",
            [
                $rewardPoints,
                $rewardRoomId,
                $roomName,
                $propertyName,
                date('Y-m-d H:i:s'),
                (int) $row['id'],
            ]
        )->rowCount();
        if ($updated !== 1) {
            return null;
        }

        return self::find((int) $row['id']);
    }
}
