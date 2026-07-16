<?php

declare(strict_types=1);

namespace App\Models;

/**
 * verified_listings — one row per room carrying the trust signals:
 * ownership review flag, GPS match flag, scam-pattern flags, computed badge.
 */
final class VerifiedListing extends BaseModel
{
    protected const TABLE = 'verified_listings';

    public static function forRoom(int $roomId): ?array
    {
        return self::first(['room_id' => $roomId]);
    }

    /** Get-or-create the row for a room. */
    public static function ensure(int $roomId): array
    {
        $existing = self::forRoom($roomId);
        if ($existing !== null) {
            return $existing;
        }

        $id = self::create(['room_id' => $roomId]);

        return self::find($id);
    }
}
