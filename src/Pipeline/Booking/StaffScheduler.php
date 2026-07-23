<?php

declare(strict_types=1);

namespace App\Pipeline\Booking;

use App\Models\Staff;

/**
 * Intelligent booking, part 5: the human side of availability. A slot is only
 * genuinely bookable when an agent is rostered for it — on shift that weekday,
 * not on leave, not already running another viewing, under their daily cap and
 * able to handle the requested mode (video call vs face-to-face).
 *
 * Graceful degradation: with no roster configured the scheduler stays out of
 * the way and every slot is considered staffed, so the pipeline behaves
 * exactly as it did before staff scheduling existed.
 */
final class StaffScheduler
{
    public const VIEWING_MINUTES = 60;

    /** Overlap window for "this agent is already busy" — 59 so back-to-back
     *  hourly slots remain bookable while a genuine clash is caught. */
    private const OVERLAP_MINUTES = 59;

    public static function rosterConfigured(): bool
    {
        return Staff::rosterConfigured();
    }

    /**
     * @param ?int $excludeBookingId booking being rescheduled — it must not
     *                               block the agent already holding it
     * @return array<int, array> agents who could take this slot, best first
     */
    public static function availableAt(string $datetime, ?string $mode = null, ?int $excludeBookingId = null): array
    {
        return Staff::onDutyAt($datetime, $mode, self::OVERLAP_MINUTES, $excludeBookingId);
    }

    /** Can this slot be staffed at all? True when no roster is configured. */
    public static function coversSlot(string $datetime, ?string $mode = null, ?int $excludeBookingId = null): bool
    {
        if (!self::rosterConfigured()) {
            return true;
        }

        return self::availableAt($datetime, $mode, $excludeBookingId) !== [];
    }

    /** Pick the least-loaded available agent, or null when nobody can take it. */
    public static function assign(string $datetime, ?string $mode = null, ?int $excludeBookingId = null): ?int
    {
        $available = self::availableAt($datetime, $mode, $excludeBookingId);

        return $available === [] ? null : (int) $available[0]['id'];
    }

    /** Does the agent already on a booking still fit the (now known) mode? */
    public static function handlesMode(?int $staffId, string $mode): bool
    {
        if ($staffId === null) {
            return false;
        }
        $staff = Staff::find($staffId);
        if ($staff === null || (int) $staff['active'] !== 1) {
            return false;
        }

        return $mode === ViewingMode::VIDEO_CALL
            ? (int) $staff['handles_video'] === 1
            : (int) $staff['handles_in_person'] === 1;
    }

    /**
     * Roster coverage for the admin page: for each of the next $days days, how
     * many agents are on duty per hour of the viewing window.
     *
     * @return array<string, array{label: string, hours: array<int, int>}>
     */
    public static function coverageGrid(int $days = 7, int $fromHour = 10, int $toHour = 19): array
    {
        $grid = [];
        $shiftsByWeekday = Staff::shiftsByWeekday();

        for ($d = 0; $d < $days; $d++) {
            $day = strtotime("+$d day");
            $date = date('Y-m-d', $day);
            $hours = [];
            for ($h = $fromHour; $h < $toHour; $h++) {
                $slot = sprintf('%s %02d:00:00', $date, $h);
                $onShift = 0;
                foreach ($shiftsByWeekday[(int) date('w', $day)] as $shift) {
                    if ($shift['starts_at'] <= sprintf('%02d:00:00', $h)
                        && $shift['ends_at'] >= sprintf('%02d:00:00', $h + 1)) {
                        $onShift++;
                    }
                }
                // Bookings and leave narrow the raw roster down to who is free.
                $hours[$h] = ['rostered' => $onShift, 'free' => count(self::availableAt($slot, 'any'))];
            }
            $grid[$date] = ['label' => date('D j M', $day), 'hours' => $hours];
        }

        return $grid;
    }
}
