<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use InvalidArgumentException;

/**
 * staff + staff_shifts + staff_time_off (migration 033) — the human roster
 * behind every viewing. The booking pipeline reads this to decide whether a
 * proposed slot can actually be staffed; the admin staff page writes it.
 *
 * Weekday numbering follows PHP's date('w'): 0 = Sunday … 6 = Saturday.
 */
final class Staff extends BaseModel
{
    protected const TABLE = 'staff';

    public const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    /** @return array<int, array> active agents, alphabetical */
    public static function active(): array
    {
        return self::all(['active' => 1], 'name ASC');
    }

    /** Is anyone at all rostered? Drives graceful degradation in the pipeline. */
    public static function rosterConfigured(): bool
    {
        return (int) Database::run(
            'SELECT COUNT(*) FROM staff_shifts sh JOIN staff s ON s.id = sh.staff_id WHERE s.active = 1'
        )->fetchColumn() > 0;
    }

    // ---- weekly shifts -----------------------------------------------------

    /** @return array<int, array> every shift of every active agent */
    public static function shifts(?int $staffId = null): array
    {
        $sql = 'SELECT sh.*, s.name AS staff_name FROM staff_shifts sh JOIN staff s ON s.id = sh.staff_id';
        $params = [];
        if ($staffId !== null) {
            $sql .= ' WHERE sh.staff_id = ?';
            $params[] = $staffId;
        }

        return Database::run($sql . ' ORDER BY sh.weekday, sh.starts_at, s.name', $params)->fetchAll();
    }

    /** @return array<int, array<int, array>> shifts keyed by weekday (0–6) */
    public static function shiftsByWeekday(): array
    {
        $grid = array_fill(0, 7, []);
        foreach (self::shifts() as $shift) {
            $grid[(int) $shift['weekday']][] = $shift;
        }

        return $grid;
    }

    public static function addShift(int $staffId, int $weekday, string $startsAt, string $endsAt): int
    {
        if ($weekday < 0 || $weekday > 6) {
            throw new InvalidArgumentException('Weekday must be 0 (Sunday) to 6 (Saturday).');
        }
        $start = self::normaliseTime($startsAt);
        $end = self::normaliseTime($endsAt);
        if ($end <= $start) {
            throw new InvalidArgumentException('A shift must end after it starts.');
        }
        if (self::find($staffId) === null) {
            throw new InvalidArgumentException('Unknown staff member.');
        }

        Database::run(
            'INSERT INTO staff_shifts (staff_id, weekday, starts_at, ends_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE ends_at = VALUES(ends_at)',
            [$staffId, $weekday, $start, $end]
        );

        return (int) Database::pdo()->lastInsertId();
    }

    public static function removeShift(int $shiftId): bool
    {
        return Database::run('DELETE FROM staff_shifts WHERE id = ?', [$shiftId])->rowCount() > 0;
    }

    // ---- one-off time off --------------------------------------------------

    /** @return array<int, array> time off that has not finished yet */
    public static function upcomingTimeOff(): array
    {
        return Database::run(
            'SELECT t.*, s.name AS staff_name FROM staff_time_off t
             JOIN staff s ON s.id = t.staff_id
             WHERE t.ends_at >= NOW() ORDER BY t.starts_at'
        )->fetchAll();
    }

    public static function addTimeOff(int $staffId, string $startsAt, string $endsAt, string $reason = ''): int
    {
        $start = strtotime($startsAt);
        $end = strtotime($endsAt);
        if ($start === false || $end === false || $end <= $start) {
            throw new InvalidArgumentException('Time off must end after it starts.');
        }
        if (self::find($staffId) === null) {
            throw new InvalidArgumentException('Unknown staff member.');
        }

        Database::run(
            'INSERT INTO staff_time_off (staff_id, starts_at, ends_at, reason) VALUES (?, ?, ?, ?)',
            [$staffId, date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $end), $reason !== '' ? $reason : null]
        );

        return (int) Database::pdo()->lastInsertId();
    }

    public static function removeTimeOff(int $timeOffId): bool
    {
        return Database::run('DELETE FROM staff_time_off WHERE id = ?', [$timeOffId])->rowCount() > 0;
    }

    // ---- duty lookups (used by the booking pipeline) -----------------------

    /**
     * Agents who can take a viewing that runs $datetime → $datetime + duration:
     * rostered that weekday, not on time off, not already booked in that
     * window, under their daily cap, and able to handle the requested mode.
     * Least-loaded agent for the day comes first, so viewings spread out.
     *
     * @param ?string $mode 'video_call' | 'in_person' | 'any' | null (not chosen yet)
     * @return array<int, array>
     */
    public static function onDutyAt(
        string $datetime,
        ?string $mode = null,
        int $durationMinutes = 60,
        ?int $excludeBookingId = null
    ): array {
        $start = strtotime($datetime);
        if ($start === false) {
            return [];
        }
        $end = $start + $durationMinutes * 60;

        // A viewing that would run past midnight can never sit inside a shift.
        if (date('Y-m-d', $start) !== date('Y-m-d', $end) && date('H:i:s', $end) !== '00:00:00') {
            return [];
        }

        $capability = match ($mode) {
            'video_call' => ' AND s.handles_video = 1',
            'in_person'  => ' AND s.handles_in_person = 1',
            'any'        => '', // roster views: capability is not the question
            // Mode still unknown: only offer agents who could do either.
            default      => ' AND s.handles_video = 1 AND s.handles_in_person = 1',
        };

        $slot = date('Y-m-d H:i:s', $start);
        $dayStart = date('Y-m-d 00:00:00', $start);
        $dayEnd = date('Y-m-d 23:59:59', $start);

        // When a booking is being *moved*, it must not count as its own clash.
        $exclude = $excludeBookingId !== null ? ' AND b.id <> ' . $excludeBookingId : '';

        return Database::run(
            "SELECT s.*,
                    (SELECT COUNT(*) FROM bookings b
                      WHERE b.staff_id = s.id AND b.status IN ('pending','confirmed')
                        AND b.viewing_datetime BETWEEN ? AND ? $exclude) AS booked_today
             FROM staff s
             JOIN staff_shifts sh ON sh.staff_id = s.id
             WHERE s.active = 1
               AND sh.weekday = ?
               AND sh.starts_at <= ?
               AND sh.ends_at >= ?
               $capability
               AND NOT EXISTS (
                   SELECT 1 FROM staff_time_off t
                    WHERE t.staff_id = s.id AND t.starts_at < ? AND t.ends_at > ?
               )
               AND NOT EXISTS (
                   SELECT 1 FROM bookings b
                    WHERE b.staff_id = s.id AND b.status IN ('pending','confirmed')
                      AND b.viewing_datetime > (? - INTERVAL ? MINUTE)
                      AND b.viewing_datetime < (? + INTERVAL ? MINUTE)
                      $exclude
               )
             GROUP BY s.id
             HAVING booked_today < s.max_daily_viewings
             ORDER BY booked_today ASC, s.id ASC",
            [
                $dayStart, $dayEnd,
                (int) date('w', $start),
                date('H:i:s', $start),
                date('H:i:s', $end),
                date('Y-m-d H:i:s', $end), $slot,
                $slot, $durationMinutes, $slot, $durationMinutes,
            ]
        )->fetchAll();
    }

    /** Bookings assigned to each agent from now on — the "my day" view. */
    public static function upcomingAssignments(int $days = 7): array
    {
        return Database::run(
            'SELECT b.id, b.viewing_datetime, b.viewing_mode, b.status, b.staff_id,
                    s.name AS staff_name, l.name AS lead_name, l.wa_phone, r.name AS room_name
             FROM bookings b
             JOIN staff s ON s.id = b.staff_id
             JOIN leads l ON l.id = b.lead_id
             LEFT JOIN rooms r ON r.id = b.room_id
             WHERE b.status IN (\'pending\',\'confirmed\')
               AND b.viewing_datetime BETWEEN NOW() AND (NOW() + INTERVAL ? DAY)
             ORDER BY b.viewing_datetime ASC',
            [$days]
        )->fetchAll();
    }

    private static function normaliseTime(string $time): string
    {
        $ts = strtotime('1970-01-01 ' . trim($time));
        if ($ts === false) {
            throw new InvalidArgumentException("Unreadable time: $time");
        }

        return date('H:i:s', $ts);
    }
}
