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
        $room['videos'] = Room::videoUrls($roomId);

        return $room;
    }

    /**
     * One room with its place in the portfolio attached: the development it
     * belongs to and the house inside it. rooms.property_name is the fallback
     * for a room imported before the hierarchy existed.
     */
    public static function findWithHierarchy(int $roomId): ?array
    {
        $room = Database::run(
            'SELECT r.*,
                    COALESCE(p.name, r.property_name) AS property_name,
                    p.address AS property_address,
                    u.name AS house_name
             FROM rooms r
             LEFT JOIN properties p ON p.id = r.property_id
             LEFT JOIN property_units u ON u.id = r.unit_id
             WHERE r.id = ? LIMIT 1',
            [$roomId]
        )->fetch();

        if (!$room) {
            return null;
        }

        $room = self::decorate($room);
        $room['images'] = Room::photoUrls($roomId);
        $room['videos'] = Room::videoUrls($roomId);

        return $room;
    }

    /**
     * Rooms like this one, in the same area — "more like where I live" for a
     * tenant who is already renting from BeLive.
     *
     * Strictly the same location: a room across town is not a similar room, so
     * unlike Room::matches() this never widens the search when nothing matches.
     * An empty list is the honest answer.
     *
     * Ranked by how near the match really is — the same house first (same front
     * door, same neighbours), then the same development, then the same room
     * type, then the closest rent at the tenure the tenant is on.
     */
    public static function similarInArea(array $room, string $tenure = '12_month', ?float $referenceRent = null, int $limit = 3): array
    {
        if (!in_array($tenure, Room::TENURES, true)) {
            $tenure = '12_month';
        }

        $roomId = (int) ($room['id'] ?? 0);
        $location = trim((string) ($room['location'] ?? ''));
        if ($roomId === 0 || $location === '') {
            return [];
        }

        // What the tenant actually pays anchors "similar price"; the room's own
        // listed rate stands in when no rent is snapshotted on their agreement.
        $reference = $referenceRent !== null && $referenceRent > 0
            ? $referenceRent
            : (Room::prices($roomId)[$tenure]['price'] ?? 0.0);

        $rows = Database::run(
            "SELECT r.*, rp.price AS price_at_tenure,
                    COALESCE(p.name, r.property_name) AS property_name,
                    u.name AS house_name
             FROM rooms r
             JOIN room_pricing rp ON rp.room_id = r.id AND rp.tenure = ?
             LEFT JOIN properties p ON p.id = r.property_id
             LEFT JOIN property_units u ON u.id = r.unit_id
             WHERE r.status = 'available'
               AND r.id <> ?
               AND r.location = ?
             ORDER BY CASE WHEN r.unit_id = ? THEN 1 ELSE 0 END DESC,
                      CASE WHEN r.property_id = ? THEN 1 ELSE 0 END DESC,
                      (r.room_type = ?) DESC,
                      ABS(rp.price - ?) ASC,
                      rp.price ASC, r.id ASC
             LIMIT " . (int) $limit,
            [
                $tenure,
                $roomId,
                $location,
                $room['unit_id'] ?? null,
                $room['property_id'] ?? null,
                (string) ($room['room_type'] ?? ''),
                $reference,
            ]
        )->fetchAll();

        return array_map([self::class, 'decorate'], $rows);
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
