<?php

declare(strict_types=1);

namespace App\Pipeline\Booking;

use App\AI\Memory\EpisodicLogger;
use App\Models\Booking;
use App\Models\Lead;

/**
 * Intelligent booking, part 2: creates the viewing booking with zero manual
 * work once the pipeline has a qualified lead + a parsed, free slot, and
 * moves the lead to converted.
 */
final class BookingCreator
{
    /** @return array the created booking row */
    public static function create(int $leadId, ?int $roomId, string $viewingDatetime, string $modelUsed, string $note = ''): array
    {
        $bookingId = Booking::create([
            'lead_id'          => $leadId,
            'room_id'          => $roomId,
            'viewing_datetime' => $viewingDatetime,
            'status'           => 'confirmed',
            'notes'            => $note !== '' ? $note : null,
        ]);

        Lead::transition($leadId, 'converted');

        EpisodicLogger::activity(
            'booking_created',
            'conversion',
            $modelUsed,
            $leadId,
            "booking #$bookingId for $viewingDatetime" . ($roomId !== null ? " (room #$roomId)" : '')
        );

        return Booking::find($bookingId);
    }
}
