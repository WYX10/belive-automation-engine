<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * property_units — the house level between a property and its rooms. A
 * property is the development ("121 Residence"); a unit is one house inside
 * it ("Unit A-12-3"); rooms belong to the house, not to the development.
 */
final class PropertyUnit extends BaseModel
{
    protected const TABLE = 'property_units';

    public const DEFAULT_NAME = 'Main house';

    /** @return array<int, array<string, mixed>> */
    public static function forProperty(int $propertyId): array
    {
        return self::all(['property_id' => $propertyId], 'name ASC');
    }

    /**
     * The house a room goes into when the caller did not name one — the owner
     * portal and the API still add rooms straight to a property. Returns the
     * property's first house, creating the default one if it has none, so the
     * hierarchy never has a room dangling outside a house.
     */
    public static function defaultForProperty(int $propertyId): int
    {
        $existing = Database::run(
            'SELECT id FROM property_units WHERE property_id = ? ORDER BY name LIMIT 1',
            [$propertyId]
        )->fetchColumn();
        if ($existing !== false) {
            return (int) $existing;
        }

        return self::create([
            'property_id' => $propertyId,
            'name' => self::DEFAULT_NAME,
            'notes' => 'Created automatically for the first room on this property.',
        ]);
    }

    /**
     * Room / tenant / availability counts per house, keyed by unit id. A
     * tenant is a distinct lead holding a confirmed or completed booking on a
     * room in that house — a cancelled booking or an unconfirmed viewing is
     * not somebody renting.
     *
     * @return array<int, array{rooms: int, tenants: int, available: int}>
     */
    public static function countsForProperty(int $propertyId): array
    {
        $rows = Database::run(
            "SELECT u.id,
                    COUNT(DISTINCT r.id) AS rooms,
                    COUNT(DISTINCT CASE WHEN r.status = 'available' THEN r.id END) AS available,
                    COUNT(DISTINCT CASE WHEN b.status IN ('confirmed', 'completed') THEN b.lead_id END) AS tenants
             FROM property_units u
             LEFT JOIN rooms r ON r.unit_id = u.id
             LEFT JOIN bookings b ON b.room_id = r.id
             WHERE u.property_id = ?
             GROUP BY u.id",
            [$propertyId]
        )->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = [
                'rooms' => (int) $row['rooms'],
                'tenants' => (int) $row['tenants'],
                'available' => (int) $row['available'],
            ];
        }

        return $out;
    }
}
