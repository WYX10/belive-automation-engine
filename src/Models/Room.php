<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * rooms — live inventory the pipeline grounds its answers in ("AI-driven
 * matching", mirroring BeLive's own phrasing). Created in migration 009.
 */
final class Room extends BaseModel
{
    protected const TABLE = 'rooms';

    /**
     * Best matches for an enquiry: area first, then budget ceiling, then type.
     * Falls back to nearby-price rooms anywhere if the area has no stock, so
     * Eve can offer an alternative instead of a dead end.
     */
    public static function matches(?string $area, ?int $budget, ?string $roomType, int $limit = 3): array
    {
        $sql = 'SELECT * FROM rooms WHERE available = 1';
        $params = [];

        if ($area !== null && $area !== '') {
            $sql .= ' AND area LIKE ?';
            $params[] = '%' . $area . '%';
        }
        if ($budget !== null && $budget > 0) {
            $sql .= ' AND price <= ?';
            $params[] = $budget;
        }
        if ($roomType !== null && $roomType !== '') {
            $sql .= ' AND room_type = ?';
            $params[] = $roomType;
        }

        $rows = Database::run($sql . ' ORDER BY price ASC LIMIT ' . (int) $limit, $params)->fetchAll();

        if ($rows === [] && $area !== null) {
            // Nothing in that area — retry without the area filter.
            return self::matches(null, $budget, $roomType, $limit);
        }

        return $rows;
    }

    /** Compact text block for prompt grounding. */
    public static function promptBlock(array $rooms): string
    {
        if ($rooms === []) {
            return "No matching rooms currently in inventory.";
        }

        $lines = [];
        foreach ($rooms as $room) {
            $features = implode(', ', json_decode($room['features'] ?? '[]', true) ?: []);
            $photos = count(json_decode($room['photos'] ?? '[]', true) ?: []);
            $lines[] = sprintf(
                '- [room_id=%d] %s — %s, %s room, RM %s/month%s%s',
                $room['id'],
                $room['name'],
                $room['area'],
                $room['room_type'],
                number_format((float) $room['price']),
                $features !== '' ? " ($features)" : '',
                $photos > 0 ? " [$photos photos available]" : ''
            );
        }

        return implode("\n", $lines);
    }
}
