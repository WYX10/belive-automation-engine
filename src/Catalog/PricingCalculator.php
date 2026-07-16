<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Models\Room;

/**
 * Resolves prices per room + tenure and computes the monthly saving versus
 * the flexible rate — the figure that makes committing to 12 months
 * persuasive on the detail page.
 */
final class PricingCalculator
{
    public static function priceFor(int $roomId, string $tenure): ?float
    {
        return Room::prices($roomId)[$tenure]['price'] ?? null;
    }

    /** @return array<string, array{price: float, is_best_value: bool}> */
    public static function allPrices(int $roomId): array
    {
        return Room::prices($roomId);
    }

    /**
     * @return array{monthly_saving: float, yearly_saving: float, pct: float}|null
     *         null when the tenure or flexible rate is missing / not cheaper
     */
    public static function savingVsFlexible(int $roomId, string $tenure): ?array
    {
        $prices = Room::prices($roomId);
        $flexible = $prices['monthly']['price'] ?? null;
        $atTenure = $prices[$tenure]['price'] ?? null;

        if ($flexible === null || $atTenure === null || $atTenure >= $flexible) {
            return null;
        }

        $saving = $flexible - $atTenure;

        return [
            'monthly_saving' => $saving,
            'yearly_saving'  => $saving * 12,
            'pct'            => round($saving / $flexible * 100, 1),
        ];
    }
}
