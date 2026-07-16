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

    /** Live schedule cross-check: is this room free around the proposed slot? */
    public static function isSlotFree(?int $roomId, string $datetime): bool
    {
        if ($roomId === null) {
            return true; // general viewing without a fixed room — no room clash possible
        }

        return Booking::conflictsAt($roomId, $datetime) === [];
    }

    /** Next free slot suggestion (hour steps within viewing hours 10:00–19:00). */
    public static function suggestAlternative(?int $roomId, string $datetime): string
    {
        $ts = strtotime($datetime);
        for ($i = 1; $i <= 16; $i++) {
            $candidate = $ts + $i * 3600;
            $hour = (int) date('G', $candidate);
            if ($hour < 10) {
                $candidate = strtotime(date('Y-m-d 10:00:00', $candidate));
            } elseif ($hour > 19) {
                $candidate = strtotime(date('Y-m-d 10:00:00', $candidate + 86400));
            }
            $slot = date('Y-m-d H:i:s', $candidate);
            if (self::isSlotFree($roomId, $slot)) {
                return $slot;
            }
        }

        return date('Y-m-d 15:00:00', strtotime('+2 day', $ts));
    }
}
