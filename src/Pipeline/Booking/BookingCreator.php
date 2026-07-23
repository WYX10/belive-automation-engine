<?php

declare(strict_types=1);

namespace App\Pipeline\Booking;

use App\AI\Memory\EpisodicLogger;
use App\Models\Booking;
use App\Models\Lead;
use App\Models\Staff;

/**
 * Intelligent booking, part 2: creates the viewing booking with zero manual
 * work once the pipeline has a qualified lead + a parsed, free slot. A booking
 * lands 'confirmed' when the customer has also picked a viewing mode (video
 * call vs face-to-face); until then it is held 'pending' at the exact proposed
 * time. The lead moves to converted only on confirmation.
 */
final class BookingCreator
{
    /** @return array the created booking row */
    public static function create(
        int $leadId,
        ?int $roomId,
        string $viewingDatetime,
        string $modelUsed,
        string $note = '',
        ?string $viewingMode = null,
        string $status = 'confirmed'
    ): array {
        // Hold the rostered agent along with the slot — an unassigned booking
        // would leave the same agent free for a second customer at that hour.
        $staffId = StaffScheduler::assign($viewingDatetime, $viewingMode);

        $bookingId = Booking::create([
            'lead_id'          => $leadId,
            'room_id'          => $roomId,
            'viewing_datetime' => $viewingDatetime,
            'viewing_mode'     => $viewingMode,
            'staff_id'         => $staffId,
            'status'           => $status,
            'notes'            => $note !== '' ? $note : null,
        ]);

        if ($status === 'confirmed') {
            Lead::transition($leadId, 'converted');
        }

        EpisodicLogger::activity(
            $status === 'confirmed' ? 'booking_created' : 'booking_proposed',
            'conversion',
            $modelUsed,
            $leadId,
            "booking #$bookingId for $viewingDatetime"
                . ($roomId !== null ? " (room #$roomId)" : '')
                . ($viewingMode !== null ? " mode=$viewingMode" : ' awaiting viewing-mode choice')
                . self::staffNote($staffId)
        );

        return Booking::find($bookingId);
    }

    /**
     * Confirms a pending slot proposal once the customer picks a viewing mode
     * (optionally moving it to a newly requested time in the same breath).
     * @return array the confirmed booking row
     */
    public static function confirm(int $bookingId, string $viewingMode, ?string $viewingDatetime = null, ?string $modelUsed = null): array
    {
        $existing = Booking::find($bookingId);
        $slot = $viewingDatetime ?? ($existing['viewing_datetime'] ?? null);

        $update = ['viewing_mode' => $viewingMode, 'status' => 'confirmed'];
        if ($viewingDatetime !== null) {
            $update['viewing_datetime'] = $viewingDatetime;
        }

        // Now that the mode is known, make sure the held agent can actually
        // run it (a video-only agent can't take a face-to-face viewing) — and
        // re-assign if the slot itself moved. When nobody fits, the booking is
        // deliberately left unassigned so it surfaces on the staff page for a
        // human to sort out, rather than sitting on an agent who can't host it.
        $staffId = $existing['staff_id'] ?? null;
        $staffId = $staffId !== null ? (int) $staffId : null;
        if ($slot !== null && ($viewingDatetime !== null || !StaffScheduler::handlesMode($staffId, $viewingMode))) {
            $update['staff_id'] = StaffScheduler::assign($slot, $viewingMode, $bookingId);
        }

        Booking::update($bookingId, $update);

        $booking = Booking::find($bookingId);
        Lead::transition((int) $booking['lead_id'], 'converted');

        EpisodicLogger::activity(
            'booking_confirmed',
            'conversion',
            $modelUsed,
            (int) $booking['lead_id'],
            "booking #$bookingId for {$booking['viewing_datetime']} mode=$viewingMode"
                . self::staffNote($booking['staff_id'] !== null ? (int) $booking['staff_id'] : null)
        );

        return $booking;
    }

    /** Audit-trail fragment naming the assigned agent (or the lack of one). */
    private static function staffNote(?int $staffId): string
    {
        if ($staffId === null) {
            return Staff::rosterConfigured() ? ' — no agent free, needs manual assignment' : '';
        }

        $staff = Staff::find($staffId);

        return $staff !== null ? " — agent {$staff['name']} (#$staffId)" : '';
    }
}
