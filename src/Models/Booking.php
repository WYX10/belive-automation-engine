<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use InvalidArgumentException;

/**
 * bookings — viewing appointments created by the booking pipeline. The rooms
 * table lives in the same migration (009); availability = no confirmed/pending
 * booking within the viewing slot for that room.
 */
final class Booking extends BaseModel
{
    protected const TABLE = 'bookings';

    public static function forLead(int $leadId): array
    {
        return self::all(['lead_id' => $leadId], 'viewing_datetime ASC');
    }

    public static function setStatus(int $id, string $status): bool
    {
        if (!in_array($status, BOOKING_STATUSES, true)) {
            throw new InvalidArgumentException("Unknown booking status: $status");
        }

        return self::update($id, ['status' => $status]);
    }

    /**
     * The lead's live slot proposal: a pending booking whose viewing mode is
     * still NULL — Eve proposed an exact time and asked "video call or
     * face-to-face?", and the customer hasn't picked yet.
     */
    public static function awaitingMode(int $leadId): ?array
    {
        $row = Database::run(
            "SELECT * FROM bookings
             WHERE lead_id = ? AND status = 'pending' AND viewing_mode IS NULL
               AND viewing_datetime >= (NOW() - INTERVAL 1 HOUR)
             ORDER BY created_at DESC LIMIT 1",
            [$leadId]
        )->fetch();

        return $row ?: null;
    }

    /** Bookings that clash with a proposed slot (±duration window) for a room. */
    public static function conflictsAt(int $roomId, string $datetime, int $durationMinutes = 60): array
    {
        return Database::run(
            "SELECT * FROM bookings
             WHERE room_id = ?
               AND status IN ('pending', 'confirmed')
               AND viewing_datetime BETWEEN (? - INTERVAL ? MINUTE) AND (? + INTERVAL ? MINUTE)",
            [$roomId, $datetime, $durationMinutes, $datetime, $durationMinutes]
        )->fetchAll();
    }

    public static function confirmedCount(): int
    {
        return self::count(['status' => 'confirmed']);
    }

    public static function upcoming(int $limit = 20): array
    {
        return Database::run(
            "SELECT b.*, l.name AS lead_name, l.wa_phone, r.name AS room_name, r.location AS room_area
             FROM bookings b
             JOIN leads l ON l.id = b.lead_id
             LEFT JOIN rooms r ON r.id = b.room_id
             WHERE b.viewing_datetime >= NOW() AND b.status IN ('pending','confirmed')
             ORDER BY b.viewing_datetime ASC
             LIMIT " . (int) $limit
        )->fetchAll();
    }
}
