<?php

declare(strict_types=1);

namespace App\Verification;

use App\AI\Memory\EpisodicLogger;
use App\AI\ModelRouter;
use App\AI\Skills\SkillSupport;
use App\Models\Room;
use App\Models\VerifiedListing;
use App\Pricing\FairPricingGuard;

/**
 * Heuristic AI scam-pattern screening on listing content — pattern-flagging
 * for ADMIN REVIEW, not a fraud-detection guarantee (never oversold as such).
 * Checks: price unrealistically low vs comparable rooms, generic/stock-like
 * description, missing concrete address.
 */
final class ScamDetector
{
    private const SYSTEM = <<<PROMPT
You screen room-rental listings for common scam patterns. You are given the listing content plus market stats for comparable rooms. Flag patterns for a human admin to review — you are a screening aid, not a verdict.

Respond with ONLY JSON:
{
  "flags": [ { "pattern": string, "severity": "low"|"medium"|"high", "detail": string } ],
  "reasoning": string
}

Patterns to check:
- Price far below comparable average (classic bait) — use the provided market stats.
- Generic or stock-photo-like description, no specific unit details.
- Missing or vague address / no fixed location.
- Pressure language ("pay deposit today to lock in").
Return an empty flags array when nothing looks wrong.
PROMPT;

    public static function screen(int $roomId): array
    {
        $room = Room::find($roomId);
        if ($room === null) {
            return [];
        }

        $pricing = FairPricingGuard::assess($roomId);

        $listing = [
            'name'        => $room['name'],
            'area'        => $room['area'],
            'room_type'   => $room['room_type'],
            'price_rm'    => (float) $room['price'],
            'address'     => $room['address'] ?: '(none given)',
            'features'    => json_decode($room['features'] ?? '[]', true) ?: [],
            'market'      => [
                'comparable_avg_rm' => $pricing['average'],
                'comparable_count'  => $pricing['sample_size'],
                'deviation_pct'     => $pricing['deviation_pct'],
            ],
        ];

        $client = ModelRouter::clientForPhase('lead_gen');
        $result = $client->generate(
            self::SYSTEM,
            [['role' => 'user', 'content' => 'LISTING: ' . json_encode($listing, JSON_UNESCAPED_UNICODE)]],
            ['max_tokens' => 500, 'temperature' => 0, 'mock_hint' => 'scam']
        );

        $parsed = SkillSupport::extractJson($result['text']) ?? ['flags' => []];
        $flags = array_values(array_filter((array) ($parsed['flags'] ?? []), 'is_array'));

        $row = VerifiedListing::ensure($roomId);
        VerifiedListing::update((int) $row['id'], [
            'scam_flags'      => json_encode($flags, JSON_UNESCAPED_UNICODE),
            'scam_checked_at' => date('Y-m-d H:i:s'),
        ]);
        ListingVerifier::recomputeBadge($roomId);

        EpisodicLogger::activity(
            'scam_screen_run',
            'lead_gen',
            $result['model'],
            null,
            "room #$roomId: " . count($flags) . ' flag(s)'
        );

        return $flags;
    }
}
