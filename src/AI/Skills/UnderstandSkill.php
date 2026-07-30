<?php

declare(strict_types=1);

namespace App\AI\Skills;

use App\AI\Memory\EpisodicLogger;
use App\AI\ModelRouter;

/**
 * Skill 1 — Understands (NLP): reads language, data and contact context;
 * extracts intent + entities (location, budget, move-in date, room type) and
 * the tenant profile from an incoming customer message.
 */
final class UnderstandSkill
{
    private const SYSTEM = <<<PROMPT
You are the NLP layer of Eve, BeLive's rental assistant (Malaysia). Extract meaning from ONE customer WhatsApp message, using conversation history for context.

Respond with ONLY a JSON object, no prose:
{
  "intent": "room_enquiry" | "price_enquiry" | "photo_request" | "booking_request" | "complaint" | "correction" | "smalltalk" | "other",
  "entities": {
    "location": string|null,       // area name e.g. "Setapak", "Cheras"
    "budget": number|null,         // monthly budget in RM, numbers only
    "move_in_date": string|null,   // as stated, e.g. "August", "next week"
    "room_type": "single"|"middle"|"master"|null,   // map "small"→single, "medium"→middle
    "tenure": "monthly"|"6_month"|"12_month"|null   // stated commitment: "short term/flexible"→monthly, "half a year"→6_month, "a year+/long term/whole course"→12_month
  },
  "tenant_profile": "student" | "working_professional" | null,
  "language": "en" | "ms" | "zh" | "mixed",
  "reasoning": string              // one sentence on how you read the message
}

Rules:
- "correction" = the customer is telling Eve something it said is wrong.
- Messages may mix English/Malay/Chinese ("bilik" = room, "sewa" = rent, "berapa" = how much).
- Extract budget from forms like "RM700", "700", "below 700", "bajet 700".
- Never invent entities that are not stated or clearly implied.
PROMPT;

    /**
     * @param array $history recent transcript rows (oldest first)
     * @return array{intent:string, entities:array, tenant_profile:?string, language:string, reasoning:string, model:string, interaction_id:int}
     */
    public static function run(int $leadId, string $message, array $history, string $phase = 'conversion'): array
    {
        $client = ModelRouter::clientForPhase($phase);

        $context = SkillSupport::historyBlock($history);
        $call = SkillSupport::generateJson(
            $client,
            self::SYSTEM,
            "$context\nNEW CUSTOMER MESSAGE:\n$message",
            ['max_tokens' => 500, 'temperature' => 0, 'mock_hint' => 'understand'],
            $leadId,
            $phase,
            'understand'
        );
        [$result, $ms] = [$call['result'], $call['ms']];

        $parsed = $call['parsed'] ?? [];
        $understanding = [
            'intent'         => $parsed['intent'] ?? 'other',
            'entities'       => [
                'location'     => $parsed['entities']['location'] ?? null,
                'budget'       => isset($parsed['entities']['budget']) ? (int) $parsed['entities']['budget'] : null,
                'move_in_date' => $parsed['entities']['move_in_date'] ?? null,
                'room_type'    => $parsed['entities']['room_type'] ?? null,
                'tenure'       => in_array($parsed['entities']['tenure'] ?? '', ['monthly', '6_month', '12_month'], true)
                    ? $parsed['entities']['tenure'] : null,
            ],
            'tenant_profile' => $parsed['tenant_profile'] ?? null,
            'language'       => $parsed['language'] ?? 'en',
            'reasoning'      => $parsed['reasoning'] ?? 'Model returned unparseable output; defaults used.',
            'model'          => $result['model'],
        ];

        // A dead model reading must not lose the two intents the pipeline acts
        // on directly — an unread "send me photos" is how a customer ends up
        // asking three times and getting a question back each time.
        if ($call['parsed'] === null) {
            $understanding['intent'] = match (true) {
                (bool) preg_match('/\b(photos?|pictures?|pics?|images?|gambar|照片|图片|圖片)\b/iu', $message) => 'photo_request',
                (bool) preg_match('/\b(price|pricing|cost|how much|berapa|harga|多少)\b/iu', $message)          => 'price_enquiry',
                default                                                                                        => $understanding['intent'],
            };
            $understanding['reasoning'] = 'Model output unreadable after a retry; intent fell back to keyword matching ('
                . $understanding['intent'] . ').';
        }

        $understanding['interaction_id'] = EpisodicLogger::log([
            'lead_id'     => $leadId,
            'phase'       => $phase,
            'skill'       => 'understand',
            'model_used'  => $result['model'],
            'direction'   => 'inbound',
            'message_in'  => $message,
            'intent'      => $understanding['intent'],
            'entities'    => $understanding['entities'],
            'reasoning'   => $understanding['reasoning'],
            'response_ms' => $ms,
        ]);

        return $understanding;
    }

}
