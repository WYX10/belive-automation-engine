<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * rooms — live inventory the pipeline grounds its answers in. As of Phase 6.5
 * pricing lives in room_pricing (per tenure — a price is never shown without
 * its tenure), photos in room_images, amenities in room_amenities.
 */
final class Room extends BaseModel
{
    protected const TABLE = 'rooms';

    public const TENURES = ['monthly', '6_month', '12_month'];

    public const TENURE_LABELS = [
        'monthly'  => 'Flexible — monthly',
        '6_month'  => '6 months',
        '12_month' => '12 months+',
    ];

    /**
     * Best matches for an enquiry: location first, then budget at the given
     * tenure (cheapest tenure by default, so a fair budget never hides a room
     * a commitment would afford), then type. Falls back area-wide.
     *
     * Each row carries price_at_tenure for the tenure used.
     */
    public static function matches(?string $location, ?int $budget, ?string $roomType, string $tenure = '12_month', int $limit = 3): array
    {
        if (!in_array($tenure, self::TENURES, true)) {
            $tenure = '12_month';
        }

        $sql = "SELECT r.*, rp.price AS price_at_tenure
                FROM rooms r
                JOIN room_pricing rp ON rp.room_id = r.id AND rp.tenure = ?
                WHERE r.status = 'available'";
        $params = [$tenure];

        if ($location !== null && $location !== '') {
            $sql .= ' AND r.location LIKE ?';
            $params[] = '%' . $location . '%';
        }
        if ($budget !== null && $budget > 0) {
            $sql .= ' AND rp.price <= ?';
            $params[] = $budget;
        }
        if ($roomType !== null && $roomType !== '') {
            $sql .= ' AND r.room_type = ?';
            $params[] = $roomType;
        }

        $rows = Database::run($sql . ' ORDER BY rp.price ASC LIMIT ' . (int) $limit, $params)->fetchAll();

        if ($rows === [] && $location !== null) {
            return self::matches(null, $budget, $roomType, $tenure, $limit);
        }

        return $rows;
    }

    /** @return array<string, array{price: float, is_best_value: bool}> keyed by tenure */
    public static function prices(int $roomId): array
    {
        $out = [];
        foreach (Database::run('SELECT tenure, price, is_best_value FROM room_pricing WHERE room_id = ?', [$roomId]) as $row) {
            $out[$row['tenure']] = ['price' => (float) $row['price'], 'is_best_value' => (bool) $row['is_best_value']];
        }

        return $out;
    }

    /** @return string[] gallery URLs, sorted */
    public static function photoUrls(int $roomId): array
    {
        return Database::run(
            'SELECT image_path FROM room_images WHERE room_id = ? ORDER BY sort_order, id',
            [$roomId]
        )->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** @return string[] BeLive-vocabulary amenity labels */
    public static function amenities(int $roomId): array
    {
        return Database::run(
            'SELECT amenity FROM room_amenities WHERE room_id = ? ORDER BY id',
            [$roomId]
        )->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Prompt grounding block. Every price is tenure-labelled and zero deposit
     * is stated — the model can only quote what it can see, so the block
     * enforces the "never a price without its tenure" rule at the source.
     */
    public static function promptBlock(array $rooms): string
    {
        if ($rooms === []) {
            return 'No matching rooms currently in inventory.';
        }

        $lines = [];
        foreach ($rooms as $room) {
            $id = (int) $room['id'];
            $prices = self::prices($id);
            $priceParts = [];
            foreach (self::TENURES as $tenure) {
                if (isset($prices[$tenure])) {
                    $priceParts[] = sprintf(
                        'RM %s/mo %s%s',
                        number_format($prices[$tenure]['price']),
                        self::TENURE_LABELS[$tenure],
                        $prices[$tenure]['is_best_value'] ? ' (best value)' : ''
                    );
                }
            }
            $amenities = self::amenities($id);
            $photoCount = count(self::photoUrls($id));

            $lines[] = sprintf(
                "- [room_id=%d | %s] %s — %s, %s room. Pricing: %s. RM 0 deposit.%s%s",
                $id,
                $room['room_code'] ?? 'no-code',
                $room['property_name'] ?: $room['name'],
                $room['location'],
                $room['room_type'],
                $priceParts !== [] ? implode(' · ', $priceParts) : 'no pricing rows',
                $amenities !== [] ? ' Amenities: ' . implode(', ', array_slice($amenities, 0, 6)) . '.' : '',
                $photoCount > 0 ? " [$photoCount photos available]" : ''
            );
        }

        return implode("\n", $lines);
    }
}
