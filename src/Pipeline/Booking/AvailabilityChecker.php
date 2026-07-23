<?php

declare(strict_types=1);

namespace App\Pipeline\Booking;

use App\AI\ModelRouter;
use App\AI\Skills\SkillSupport;
use App\Models\Booking;

/**
 * Intelligent booking, part 1 (proposal channel #6): natural-language time
 * parsing ("tomorrow 3pm", "esok petang", "this Saturday morning") via the
 * live conversation model, plus a live schedule cross-check against existing
 * bookings for the room.
 */
final class AvailabilityChecker
{
    private const SYSTEM = <<<PROMPT
You convert a customer's natural-language viewing time (English/Malay/Chinese) into an exact datetime.

You are given NOW (the current datetime, timezone Asia/Kuala_Lumpur). Respond with ONLY JSON:
{
  "datetime": "YYYY-MM-DD HH:MM:SS" | null,   // null when no concrete time was proposed
  "confident": boolean,                        // false when you had to guess the hour
  "reasoning": string
}

Rules:
- "tomorrow 3pm" → tomorrow at 15:00. "esok" = tomorrow, "petang" = ~15:00, "malam" = ~20:00, "pagi" = ~10:00.
- Part-of-day without a clock time still yields an exact datetime with confident=false: "morning" → 10:00, "afternoon" → 15:00, "evening"/"night" → 20:00 ("tuesday afternoon" → next Tuesday 15:00, confident=false).
- A bare day ("Saturday") with no time → that day at 15:00, confident=false.
- Never place the viewing in the past — roll to the next valid occurrence.
PROMPT;

    /** @return array{datetime: ?string, confident: bool, reasoning: string} */
    public static function parseRequestedTime(string $customerText): array
    {
        $client = ModelRouter::clientForPhase('conversion');

        $result = $client->generate(
            self::SYSTEM,
            [['role' => 'user', 'content' => 'NOW: ' . date('Y-m-d H:i:s (l)') . "\nCUSTOMER: $customerText"]],
            ['max_tokens' => 250, 'temperature' => 0, 'mock_hint' => 'time_parse']
        );

        $parsed = SkillSupport::extractJson($result['text']) ?? [];
        $datetime = $parsed['datetime'] ?? null;

        // Validate the model's format; discard anything unparseable or past.
        if ($datetime !== null) {
            $ts = strtotime((string) $datetime);
            $datetime = ($ts !== false && $ts > time() - 300) ? date('Y-m-d H:i:s', $ts) : null;
        }

        return [
            'datetime'  => $datetime,
            'confident' => (bool) ($parsed['confident'] ?? false),
            'reasoning' => (string) ($parsed['reasoning'] ?? ''),
        ];
    }

    /**
     * Live schedule cross-check. Two things have to be true: the room is free,
     * and an agent is rostered to run the viewing (see StaffScheduler — with no
     * roster configured that half always passes).
     */
    public static function isSlotFree(?int $roomId, string $datetime, ?string $mode = null): bool
    {
        // A general viewing without a fixed room can't clash on the room side.
        if ($roomId !== null && Booking::conflictsAt($roomId, $datetime) !== []) {
            return false;
        }

        return StaffScheduler::coversSlot($datetime, $mode);
    }

    /** Next free slot suggestion (hour steps within viewing hours 10:00–19:00). */
    public static function suggestAlternative(?int $roomId, string $datetime, ?string $mode = null): string
    {
        $ts = strtotime($datetime);
        $cursor = $ts;

        // Walk forward an hour at a time, staying inside viewing hours. The
        // horizon is generous because a roster gap (weekend off, leave) can
        // push the next staffed slot several days out.
        for ($i = 1; $i <= 100; $i++) {
            $cursor += 3600;
            $hour = (int) date('G', $cursor);
            if ($hour < 10) {
                $cursor = strtotime(date('Y-m-d 10:00:00', $cursor));
            } elseif ($hour > 19) {
                $cursor = strtotime(date('Y-m-d 10:00:00', $cursor + 86400));
            }
            $slot = date('Y-m-d H:i:s', $cursor);
            if (self::isSlotFree($roomId, $slot, $mode)) {
                return $slot;
            }
        }

        return date('Y-m-d 15:00:00', strtotime('+2 day', $ts));
    }
}
