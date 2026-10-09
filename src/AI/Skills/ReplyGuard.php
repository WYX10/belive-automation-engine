<?php

declare(strict_types=1);

namespace App\AI\Skills;

use App\Models\Room;

/** Catch unsupported monetary claims before a model draft reaches a tenant. */
final class ReplyGuard
{
    public static function priceError(string $text, array $rooms, array $lead): ?string
    {
        $allowed = [];
        $atTenure = [];
        $savings = [];
        $deposits = [];
        foreach ($rooms as $room) {
            $priceMap = Room::prices((int) $room['id']);
            $prices = array_column($priceMap, 'price');
            foreach ($priceMap as $tenure => $price) {
                $atTenure[$tenure][] = (float) $price['price'];
            }
            foreach ($prices as $price) {
                $allowed[] = (float) $price;
            }
            // Savings and deposits may be stated only when inventory supports them.
            foreach ($prices as $a) {
                foreach ($prices as $b) {
                    $savings[] = abs((float) $a - (float) $b);
                }
            }
            if (isset($room['deposit_amount'])) {
                $deposits[] = (float) $room['deposit_amount'];
            }
        }
        // The tenant's own budget is not a claim about rent. Remove only the
        // explicitly labelled, correct budget phrase before checking prices.
        if (!empty($lead['budget'])) {
            $text = preg_replace_callback('/\b(?:your budget|bajet anda|你的预算)\s*(?:is|of|:)?\s*RM\s*([\d,]+(?:\.\d{1,2})?)/iu',
                static fn ($m) => (float) str_replace(',', '', $m[1]) === (float) $lead['budget'] ? '' : $m[0], $text);
        }
        preg_match_all('/\bRM\s*([\d,]+(?:\.\d{1,2})?)/iu', $text, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as [$amount, $offset]) {
            $value = (float) str_replace(',', '', $amount);
            $before = substr($text, max(0, $offset - 35), min(35, $offset));
            $after = preg_split('/[.;!?]|\bRM\b/iu', substr($text, $offset + strlen($amount), 65), 2)[0];
            $options = $allowed;
            if (preg_match('/\b(save|saving|savings|jimat)\s*(?:(?:up to|of|around|about)\s*)?:?\s*RM\s*$/iu', $before)) {
                $options = $savings;
            } elseif (preg_match('/\bdeposit\s*(?:(?:is|of)\s*)?:?\s*RM\s*$/iu', $before) || preg_match('/^\s*(?:for |as )?deposit\b/i', $after)) {
                $options = $deposits;
            } elseif (preg_match('/\b(12[ -]?months?|6[ -]?months?|monthly|flexible)\b/i', $after, $tenureMatch)) {
                $tenure = str_starts_with($tenureMatch[1], '12') ? '12_month' : (str_starts_with($tenureMatch[1], '6') ? '6_month' : 'monthly');
                $options = $atTenure[$tenure] ?? [];
            }
            if (!in_array($value, $options, true)) {
                return 'Draft quoted RM ' . $amount . ' without support in the offered rooms\' live prices, deposits or savings. Verify the exact room and tenure before quoting.';
            }
        }
        return null;
    }
}
