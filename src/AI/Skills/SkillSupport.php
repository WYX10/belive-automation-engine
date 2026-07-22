<?php

declare(strict_types=1);

namespace App\AI\Skills;

/**
 * Tiny shared helpers for the four skills: robust JSON extraction from model
 * output and a timing wrapper. No business logic.
 */
final class SkillSupport
{
    /** Pull the first JSON object out of a model reply (handles code fences). */
    public static function extractJson(string $text): ?array
    {
        // Fast path: the whole reply is JSON.
        $decoded = json_decode(trim($text), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Strip ```json fences, then take the outermost {...} span.
        $clean = preg_replace('/```(?:json)?/i', '', $text);
        $start = strpos($clean, '{');
        $end = strrpos($clean, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($clean, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return array{0: mixed, 1: int} [result, elapsed ms] */
    public static function timed(callable $fn): array
    {
        $started = hrtime(true);
        $result = $fn();

        return [$result, (int) ((hrtime(true) - $started) / 1_000_000)];
    }

    /** Readable transcript for any skill's prompt. @param array $history oldest first */
    public static function historyBlock(array $history): string
    {
        if ($history === []) {
            return 'CONVERSATION HISTORY: (first contact)';
        }

        $lines = [];
        foreach ($history as $row) {
            $who = $row['direction'] === 'inbound' ? 'Customer' : 'Eve';
            $text = $row['direction'] === 'inbound' ? $row['message_in'] : $row['message_out'];
            if ($text !== null && $text !== '') {
                $lines[] = "$who: $text";
            }
        }

        if ($lines === []) {
            return 'CONVERSATION HISTORY: (first contact)';
        }

        return "CONVERSATION HISTORY (oldest first):\n" . implode("\n", array_slice($lines, -12));
    }

    /**
     * What has already happened in this conversation. Without this the skills
     * re-decide every turn from scratch and loop — re-sending photos and
     * re-asking "would you like the pricing?" after the customer already said
     * yes.
     *
     * @param array $history oldest first
     * @return array{photos_sent:bool, price_quoted:bool, price_asked:bool, photo_offers:int, block:string}
     */
    public static function conversationState(array $history): array
    {
        $photosSent = false;
        $priceQuoted = false;
        $priceAsked = false;
        $photoOffers = 0;

        foreach ($history as $row) {
            if ($row['direction'] === 'outbound') {
                if (($row['message_kind'] ?? '') === 'photos') {
                    $photosSent = true;
                }
                if (($row['message_kind'] ?? '') === 'price_quote') {
                    $priceQuoted = true;
                }
                if (preg_match('/pricing|price/i', (string) ($row['message_out'] ?? ''))
                    && str_contains((string) ($row['message_out'] ?? ''), '?')) {
                    $photoOffers++;
                }
                continue;
            }

            // Inbound: an explicit ask for price, including a plain "yes" to
            // Eve's own "shall I share the pricing?" offer.
            $in = (string) ($row['message_in'] ?? '');
            if (preg_match('/\b(price|pricing|cost|how much|rate|rental fee|berapa|harga|sewa berapa|多少|价格|價格)\b/iu', $in)
                || (($row['intent'] ?? '') === 'price_enquiry')
                || ($photoOffers > 0 && preg_match('/^\s*(yes|yeah|yup|ok(ay)?|sure|boleh|ya|好|要)\b/i', $in))) {
                $priceAsked = true;
            }
        }

        $facts = [
            'room photos already sent to this customer: ' . ($photosSent ? 'YES' : 'no'),
            'a price figure already given: ' . ($priceQuoted ? 'YES' : 'no'),
            'customer has already asked for / agreed to see pricing: ' . ($priceAsked ? 'YES' : 'no'),
            'times Eve has already offered to share pricing: ' . $photoOffers,
        ];

        $block = "CONVERSATION STATE (what has ALREADY happened — do not repeat it):\n- "
            . implode("\n- ", $facts);

        if ($photosSent && $priceAsked && !$priceQuoted) {
            $block .= "\nThe customer is waiting on pricing they have already asked for. Answer with the actual"
                . " figures now (per tenure). Do NOT send photos again and do NOT ask permission again.";
        }

        return [
            'photos_sent'  => $photosSent,
            'price_quoted' => $priceQuoted,
            'price_asked'  => $priceAsked,
            'photo_offers' => $photoOffers,
            'block'        => $block,
        ];
    }
}
