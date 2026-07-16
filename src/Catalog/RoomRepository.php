<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Core\Database;
use App\Models\Room;

/**
 * Read side of the public room catalog — every page read goes through here,
 * no raw SQL in page files. Rows come back with 'prices' (all tenures),
 * 'cover_image' and 'amenities' attached, ready for cards.
 */
final class RoomRepository
{
    /**
     * @param array{location?: ?string, room_type?: ?string, tenure?: ?string,
     *              max_price?: ?int, amenity?: ?string, include_unavailable?: bool} $filters
     */
    public static function filter(array $filters = [], int $limit = 60): array
    {
        $tenure = in_array($filters['tenure'] ?? '', Room::TENURES, true) ? $filters['tenure'] : '12_month';

        $sql = "SELECT r.*, rp.price AS price_at_tenure
                FROM rooms r
                JOIN room_pricing rp ON rp.room_id = r.id AND rp.tenure = ?";
        $params = [$tenure];
        $where = [];

        if (empty($filters['include_unavailable'])) {
            $where[] = "r.status = 'available'";
        }
        if (!empty($filters['location'])) {
            $where[] = 'r.location LIKE ?';
            $params[] = '%' . $filters['location'] . '%';
        }
        if (!empty($filters['room_type']) && in_array($filters['room_type'], ['single', 'middle', 'master'], true)) {
            $where[] = 'r.room_type = ?';
            $params[] = $filters['room_type'];
        }
        if (!empty($filters['max_price'])) {
            $where[] = 'rp.price <= ?';
            $params[] = (int) $filters['max_price'];
        }
        if (!empty($filters['amenity'])) {
            $where[] = 'EXISTS (SELECT 1 FROM room_amenities a WHERE a.room_id = r.id AND a.amenity = ?)';
            $params[] = $filters['amenity'];
        }

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY rp.price ASC LIMIT ' . (int) $limit;

        return array_map([self::class, 'decorate'], Database::run($sql, $params)->fetchAll());
    }

    /** One room with everything the detail page needs, or null. */
    public static function findWithDetails(int $roomId): ?array
    {
        $room = Room::find($roomId);
        if ($room === null) {
            return null;
        }

        $room = self::decorate($room);
        $room['images'] = Room::photoUrls($roomId);

        return $room;
    }

    /** Featured rooms for the homepage: cheapest available across locations. */
    public static function featured(int $limit = 6): array
    {
        return self::filter([], $limit);
    }

    /** @return array<int, array{location: string, n: int}> for location cards */
    public static function locations(): array
    {
        return Database::run(
            "SELECT location, COUNT(*) AS n FROM rooms WHERE status = 'available'
             GROUP BY location ORDER BY n DESC, location"
        )->fetchAll();
    }

    private static function decorate(array $room): array
    {
        $id = (int) $room['id'];
        $room['prices'] = Room::prices($id);
        $room['amenities'] = Room::amenities($id);
        $room['cover_image'] = Room::photoUrls($id)[0] ?? null;

        return $room;
    }
}
