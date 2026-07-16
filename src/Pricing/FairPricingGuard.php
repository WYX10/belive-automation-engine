<?php

declare(strict_types=1);

namespace App\Pricing;

use App\Core\Database;
use App\Models\Room;

/**
 * Fair Pricing Guard — compares a listing's price against the average of
 * comparable rooms (same area + room type) in live inventory. Tenant side:
 * a trust signal. Owner side: a pricing suggestion.
 */
final class FairPricingGuard
{
    /** Above this % over comparable average → flagged as above market. */
    public const OVERPRICED_THRESHOLD = 15.0;
    /** Below this % under comparable average → suspicious-cheap (scam signal). */
    public const UNDERPRICED_THRESHOLD = 25.0;

    /**
     * @return array{room_id:int, price:float, average:?float, sample_size:int,
     *               deviation_pct:?float, verdict:string, message:string}
     */
    public static function assess(int $roomId): array
    {
        $room = Room::find($roomId);
        if ($room === null) {
            return ['room_id' => $roomId, 'price' => 0.0, 'average' => null, 'sample_size' => 0, 'deviation_pct' => null, 'verdict' => 'unknown', 'message' => 'Room not found.'];
        }

        // Benchmark on the flexible-monthly tenure so it's always like-for-like.
        $price = \App\Catalog\PricingCalculator::priceFor($roomId, 'monthly');
        if ($price === null) {
            return ['room_id' => $roomId, 'price' => 0.0, 'average' => null, 'sample_size' => 0, 'deviation_pct' => null, 'verdict' => 'unknown', 'message' => 'Room has no monthly pricing row.'];
        }

        $stats = Database::run(
            "SELECT AVG(rp.price) AS avg_price, COUNT(*) AS n
             FROM rooms r
             JOIN room_pricing rp ON rp.room_id = r.id AND rp.tenure = 'monthly'
             WHERE r.location = ? AND r.room_type = ? AND r.id <> ? AND r.status = 'available'",
            [$room['location'], $room['room_type'], $roomId]
        )->fetch();

        // Thin data in the exact area → widen to same room type across areas.
        if ((int) $stats['n'] < 2) {
            $stats = Database::run(
                "SELECT AVG(rp.price) AS avg_price, COUNT(*) AS n
                 FROM rooms r
                 JOIN room_pricing rp ON rp.room_id = r.id AND rp.tenure = 'monthly'
                 WHERE r.room_type = ? AND r.id <> ? AND r.status = 'available'",
                [$room['room_type'], $roomId]
            )->fetch();
        }

        $sample = (int) $stats['n'];
        if ($sample === 0 || $stats['avg_price'] === null) {
            return ['room_id' => $roomId, 'price' => $price, 'average' => null, 'sample_size' => 0, 'deviation_pct' => null, 'verdict' => 'insufficient_data', 'message' => 'Not enough comparable rooms to benchmark this price.'];
        }

        $average = round((float) $stats['avg_price'], 2);
        $deviation = round(($price - $average) / $average * 100, 1);

        [$verdict, $message] = match (true) {
            $deviation > self::OVERPRICED_THRESHOLD =>
                ['above_market', sprintf('Flexible-monthly rate is %.1f%% above the RM %s average of %d comparable rooms.', $deviation, number_format($average), $sample)],
            $deviation < -self::UNDERPRICED_THRESHOLD =>
                ['suspiciously_low', sprintf('Flexible-monthly rate is %.1f%% below the RM %s comparable average — verify before trusting (classic bait-listing signal).', abs($deviation), number_format($average))],
            default =>
                ['fair', sprintf('Within the fair range: %+.1f%% vs the RM %s flexible-monthly average of %d comparable rooms.', $deviation, number_format($average), $sample)],
        };

        return [
            'room_id'       => $roomId,
            'price'         => $price,
            'average'       => $average,
            'sample_size'   => $sample,
            'deviation_pct' => $deviation,
            'verdict'       => $verdict,
            'message'       => $message,
        ];
    }
}
