<?php

declare(strict_types=1);

namespace App\AI\Skills;

use App\AI\LlmClient;
use App\AI\Memory\EpisodicLogger;

/**
 * Tiny shared helpers for the four skills: robust JSON extraction from model
 * output and a timing wrapper. No business logic.
 */
final class SkillSupport
{
    /**
     * Eve claiming photos are on their way. Used in two places: to notice a
     * promise she never kept, and to stop a reply going out that says "sending
     * photos now" with nothing attached.
     */
    public const PHOTO_PROMISE = '/\b(sending|send(ing)? (you|over|through)|here (you go|are|\'?s)|attach(ing|ed)?|coming (through|your way)|on (its|their) way|share)\b[^.!?\n]{0,60}\b(photos?|pictures?|pics?|images?|gambar|照片)\b/iu';

    /** Any mention of room photos, in any of the three languages Eve speaks. */
    private const PHOTO_WORD = '/\b(photos?|pictures?|pics?|images?|gambar|照片|图片|圖片)\b/iu';

    /**
     * The customer asking to see the room. Deliberately decided from the raw
     * text rather than the model's intent label: this is the one request the
     * pipeline acts on by sending something, so it must survive a model that
     * mis-labels the turn.
     */
    public static function asksForPhotos(string $text): bool
    {
        return preg_match(self::PHOTO_WORD, $text) === 1
            || preg_match('/\b(tengok bilik|看看房间|看看房間)\b/iu', $text) === 1;
    }

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
        if ($start === false) {
            return null;
        }

        $end = strrpos($clean, '}');
        if ($end !== false && $end > $start) {
            $decoded = json_decode(substr($clean, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Last resort: the reply ran out of tokens mid-object. Close whatever
        // is still open and keep the fields that did arrive — a decision with
        // its first six fields intact beats falling back to blank defaults.
        return self::repairTruncatedJson(substr($clean, $start));
    }

    /**
     * A model call whose answer MUST be JSON. Asks the provider for its native
     * JSON mode, and retries once — with a bigger budget and a blunt reminder —
     * when the answer still cannot be read.
     *
     * This exists because every skill degraded silently on a parse failure:
     * defaults were substituted, the learned rules in the prompt were thrown
     * away with them, and the only trace was one line of reasoning text. Which
     * model the admin assigns to a phase should not change whether a decision
     * survives.
     *
     * @return array{parsed: ?array, result: array, ms: int, attempts: int}
     */
    public static function generateJson(
        LlmClient $client,
        string $system,
        string $prompt,
        array $opts = [],
        ?int $leadId = null,
        string $phase = 'conversion',
        string $skill = ''
    ): array {
        $opts['json'] = true;
        $maxTokens = (int) ($opts['max_tokens'] ?? 1024);

        [$result, $ms] = self::timed(fn () => $client->generate(
            $system,
            [['role' => 'user', 'content' => $prompt]],
            $opts
        ));
        $parsed = self::extractJson($result['text']);

        if ($parsed !== null && !($result['truncated'] ?? false)) {
            return ['parsed' => $parsed, 'result' => $result, 'ms' => $ms, 'attempts' => 1];
        }

        // A truncated-but-repairable answer is usable; a broken one is not.
        // Either way the retry is worth it, so log what went wrong first.
        $why = ($result['truncated'] ?? false) ? 'hit the token budget' : 'was not valid JSON';
        EpisodicLogger::activity(
            'model_json_retry',
            $phase,
            $result['model'],
            $leadId,
            trim("$skill output $why — retrying with a larger budget. Got: "
                . mb_substr(str_replace("\n", ' ', $result['text']), 0, 200))
        );

        $retryOpts = $opts;
        $retryOpts['max_tokens'] = min(4000, max(1200, $maxTokens * 2));
        [$retry, $retryMs] = self::timed(fn () => $client->generate(
            $system . "\n\nCRITICAL: reply with the JSON object ONLY — no preamble, no code fence,"
                . ' no commentary after it. Keep every string field under 200 characters so the'
                . ' object always closes.',
            [['role' => 'user', 'content' => $prompt]],
            $retryOpts
        ));
        $retryParsed = self::extractJson($retry['text']);

        if ($retryParsed !== null) {
            return ['parsed' => $retryParsed, 'result' => $retry, 'ms' => $ms + $retryMs, 'attempts' => 2];
        }

        // Both attempts unusable. Hand back whatever the first pass salvaged
        // (possibly null) — the caller decides what is safe to do without it.
        EpisodicLogger::activity(
            'model_json_failed',
            $phase,
            $retry['model'],
            $leadId,
            trim("$skill could not produce parseable JSON in 2 attempts; deterministic fallbacks applied.")
        );

        return ['parsed' => $parsed, 'result' => $retry, 'ms' => $ms + $retryMs, 'attempts' => 2];
    }

    /**
     * Close a JSON object that stopped mid-flight: finish the dangling string,
     * drop the half-written key, then balance the braces and brackets.
     */
    private static function repairTruncatedJson(string $fragment): ?array
    {
        $stack = [];
        $inString = false;
        $escaped = false;
        $cutAt = -1; // offset of the comma ending the last complete top-level pair

        for ($i = 0, $len = strlen($fragment); $i < $len; $i++) {
            $char = $fragment[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{' || $char === '[') {
                $stack[] = $char;
            } elseif ($char === '}' || $char === ']') {
                array_pop($stack);
            } elseif ($char === ',' && count($stack) === 1) {
                // Back at the root object between two fields: everything before
                // this comma is a complete, closed set of pairs.
                $cutAt = $i;
            }
        }

        if ($stack === []) {
            return null; // balanced already — malformed for some other reason
        }

        // Cutting back to the last top-level comma leaves only the root open,
        // so a single "}" always finishes it. Anything else is guesswork.
        if ($cutAt > 0) {
            $decoded = json_decode(substr($fragment, 0, $cutAt) . '}', true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
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
     * @return array{photos_sent:bool, price_quoted:bool, price_asked:bool, photo_offers:int,
     *               photos_promised:bool, photos_wanted:bool, photo_rooms:string[], block:string}
     */
    public static function conversationState(array $history): array
    {
        $photosSent = false;
        $priceQuoted = false;
        $priceAsked = false;
        $priceOffers = 0;
        $photoOffered = false;   // Eve asked "want to see photos?" and is awaiting the answer
        $photosPromised = false; // Eve SAID photos were coming but none were attached
        $photosWanted = false;   // customer asked for photos, or accepted the offer
        $photoRooms = [];        // rooms this customer has already been shown

        // Eve's messages arrive in bursts — the photo messages and the reply
        // that introduces them are one turn. A promise only counts as broken
        // when its whole burst went out without a single photo in it.
        $burstPromise = false;
        $burstPhotos = false;
        $closeBurst = static function () use (&$burstPromise, &$burstPhotos, &$photosPromised, &$photosWanted): void {
            if ($burstPromise && !$burstPhotos) {
                $photosPromised = true;
                $photosWanted = true;
            }
            $burstPromise = false;
            $burstPhotos = false;
        };

        foreach ($history as $row) {
            if ($row['direction'] === 'outbound') {
                $out = (string) ($row['message_out'] ?? '');
                $kind = (string) ($row['message_kind'] ?? '');

                if ($kind === 'photos') {
                    $photosSent = true;
                    $burstPhotos = true;
                    $photosPromised = false; // an earlier promise is now kept
                    $photosWanted = false;   // and the request is satisfied

                    // Which room they have already seen. Without this, photos of
                    // one room count as having answered a question about another.
                    if (preg_match('/room photos: (.+)\]/u', $out, $m)) {
                        $photoRooms[] = trim($m[1]);
                    }
                }
                if ($kind === 'price_quote') {
                    $priceQuoted = true;
                }
                if (preg_match('/pricing|price/i', $out) && str_contains($out, '?')) {
                    $priceOffers++;
                }
                if ($kind !== 'photos' && preg_match(self::PHOTO_PROMISE, $out)) {
                    $burstPromise = true;
                }

                $photoOffered = $kind !== 'photos'
                    && preg_match('/\b(photos?|pictures?|pics?|images?|gambar|照片)\b/iu', $out) === 1
                    && str_contains($out, '?');
                continue;
            }

            $closeBurst();

            // Inbound: an explicit ask for price, including a plain "yes" to
            // Eve's own "shall I share the pricing?" offer.
            $in = (string) ($row['message_in'] ?? '');
            $agrees = (bool) preg_match('/^\s*(yes|yeah|yup|yep|yap|ok(ay)?|sure|please|boleh|nak|ya|好|要)\b/i', $in);

            if (preg_match('/\b(price|pricing|cost|how much|rate|rental fee|berapa|harga|sewa berapa|多少|价格|價格)\b/iu', $in)
                || (($row['intent'] ?? '') === 'price_enquiry')
                || ($priceOffers > 0 && $agrees)) {
                $priceAsked = true;
            }

            // A photo request is just as explicit, and until now nothing in the
            // pipeline recorded it — so a "yes please" to "want photos?" was
            // read as agreement to pricing and the photos never went out.
            if (($row['intent'] ?? '') === 'photo_request'
                || self::asksForPhotos($in)
                || ($photoOffered && $agrees)) {
                $photosWanted = true;
            }
        }

        $closeBurst(); // a promise in the final turn has nothing following it

        $facts = [
            'room photos already sent to this customer: ' . ($photosSent ? 'YES' : 'no'),
            'a price figure already given: ' . ($priceQuoted ? 'YES' : 'no'),
            'customer has already asked for / agreed to see pricing: ' . ($priceAsked ? 'YES' : 'no'),
            'customer is waiting on room photos: ' . ($photosWanted ? 'YES' : 'no'),
            'times Eve has already offered to share pricing: ' . $priceOffers,
        ];

        $block = "CONVERSATION STATE (what has ALREADY happened — do not repeat it):\n- "
            . implode("\n- ", $facts);

        if ($photosSent && $priceAsked && !$priceQuoted) {
            $block .= "\nThe customer is waiting on pricing they have already asked for. Answer with the actual"
                . " figures now (per tenure). Do NOT send photos again and do NOT ask permission again.";
        }
        if ($photosPromised) {
            $block .= "\nEve already told this customer photos were on the way and never sent any. Send them"
                . ' with this reply — do not promise them a second time.';
        }

        return [
            'photos_sent'     => $photosSent,
            'price_quoted'    => $priceQuoted,
            'price_asked'     => $priceAsked,
            'photo_offers'    => $priceOffers,
            'photos_promised' => $photosPromised,
            'photos_wanted'   => $photosWanted,
            'photo_rooms'     => array_values(array_unique($photoRooms)),
            'block'           => $block,
        ];
    }
}
