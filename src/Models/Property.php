<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Property extends BaseModel
{
    protected const TABLE = 'properties';

    /** @return array<int, array<string, mixed>> */
    public static function forOwner(string $ownerName): array
    {
        return self::all(['owner_name' => $ownerName], 'name ASC');
    }

    /**
     * House / room / tenant / availability counts per property, keyed by
     * property id — what the top level of the admin drill-down summarises.
     * Counted in one query because the room page renders every property card
     * at once.
     *
     * @param int[] $propertyIds
     * @return array<int, array{houses: int, rooms: int, tenants: int, available: int}>
     */
    public static function portfolioCounts(array $propertyIds): array
    {
        if ($propertyIds === []) {
            return [];
        }
        $placeholders = rtrim(str_repeat('?, ', count($propertyIds)), ', ');

        $rows = Database::run(
            "SELECT p.id,
                    COUNT(DISTINCT u.id) AS houses,
                    COUNT(DISTINCT r.id) AS rooms,
                    COUNT(DISTINCT CASE WHEN r.status = 'available' THEN r.id END) AS available,
                    COUNT(DISTINCT CASE WHEN b.status IN ('confirmed', 'completed') THEN b.lead_id END) AS tenants
             FROM properties p
             LEFT JOIN property_units u ON u.property_id = p.id
             LEFT JOIN rooms r ON r.property_id = p.id
             LEFT JOIN bookings b ON b.room_id = r.id
             WHERE p.id IN ($placeholders)
             GROUP BY p.id",
            array_map('intval', $propertyIds)
        )->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = [
                'houses' => (int) $row['houses'],
                'rooms' => (int) $row['rooms'],
                'tenants' => (int) $row['tenants'],
                'available' => (int) $row['available'],
            ];
        }

        return $out;
    }
}
