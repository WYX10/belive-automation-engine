<?php

declare(strict_types=1);

namespace App\Models;

/**
 * move_in_logs — timestamped room-condition photos (dispute prevention).
 */
final class MoveInLog extends BaseModel
{
    protected const TABLE = 'move_in_logs';

    public static function forRoom(int $roomId): array
    {
        return self::all(['room_id' => $roomId], 'taken_at ASC');
    }

    public static function forLead(int $leadId): array
    {
        return self::all(['lead_id' => $leadId], 'taken_at ASC');
    }
}
