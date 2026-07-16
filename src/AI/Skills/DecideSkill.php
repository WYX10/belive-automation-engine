<?php

declare(strict_types=1);

namespace App\AI\Skills;

use App\AI\Memory\EpisodicLogger;
use App\AI\ModelRouter;
use App\Models\Room;

/**
 * Skill 2 — Decides (Situational AI): different situation, different response,
 * not a fixed script. Takes the extracted understanding + retrieved learned
 * rules + live room inventory and decides qualification, closing probability,
 * next action and room matching (a student gets budget rooms, a working
 * professional gets premium units — BeLive's own "AI-driven matching").
 */
final class DecideSkill
{
    private const SYSTEM = <<<PROMPT
You are the decision layer of Eve, BeLive's rental assistant. Given the customer's extracted intent, their profile, live room inventory, and Eve's learned rules, decide what happens next.

Respond with ONLY a JSON object:
{
  "qualified": boolean,               // real rental interest with actionable detail?
  "closing_probability": 0-100,       // honest estimate this lead converts
  "lead_signals": string[],           // concrete observed signals, e.g. "gave budget RM700"
  "next_action": "answer_directly" | "request_info" | "book_viewing" | "escalate",
  "send_photos_first": boolean,       // send room photos BEFORE any price figure?
  "recommended_room_ids": number[],   // ids from the inventory list, best first, max 3
  "recommendation": string,           // one line for the admin dashboard
  "reasoning": string                 // 1-2 sentences: why this decision
}

Decision guidance:
- Situational matching: students → budget/small rooms; working professionals → medium/master/premium. Different situations get different answers, never a fixed script.
- "book_viewing" when the customer proposes or agrees to a time/viewing.
- "escalate" for complaints, legal/payment disputes, or anything Eve should not answer alone.
- "request_info" only when a genuinely needed detail (like area) is missing.
- If a LEARNED RULE applies to this context, you MUST follow it (e.g. a sequencing rule that says send photos before price ⇒ send_photos_first=true) and mention it in reasoning.
PROMPT;

    /**
     * @return array{qualified:bool, closing_probability:int, lead_signals:array, next_action:string,
     *               send_photos_first:bool, recommended_room_ids:array, recommendation:string,
     *               reasoning:string, model:string, rooms:array, memory_ids:array}
     */
    public static function run(array $lead, array $understanding, array $memory, string $phase = 'conversion'): array
    {
        $client = ModelRouter::clientForPhase($phase);

        $entities = $understanding['entities'];
        $rooms = Room::matches(
            $entities['location'] ?? $lead['location'] ?? null,
            $entities['budget'] ?? (is_numeric($lead['budget'] ?? null) ? (int) $lead['budget'] : null),
            $entities['room_type'] ?? $lead['room_type'] ?? null
        );

        $prompt = implode("\n\n", array_filter([
            $memory['block'] ?? '',
            'CUSTOMER PROFILE: ' . json_encode([
                'name'           => $lead['name'],
                'status'         => $lead['status'],
                'known_location' => $lead['location'],
                'known_budget'   => $lead['budget'],
                'tenant_profile' => $understanding['tenant_profile'] ?? $lead['tenant_profile'],
                'is_returning'   => $lead['is_returning'] ?? false,
            ], JSON_UNESCAPED_UNICODE),
            'EXTRACTED UNDERSTANDING: ' . json_encode([
                'intent'   => $understanding['intent'],
                'entities' => $entities,
                'language' => $understanding['language'],
            ], JSON_UNESCAPED_UNICODE),
            "LIVE ROOM INVENTORY (matched):\n" . Room::promptBlock($rooms),
        ]));

        [$result, $ms] = SkillSupport::timed(fn () => $client->generate(
            self::SYSTEM,
            [['role' => 'user', 'content' => $prompt]],
            ['max_tokens' => 600, 'temperature' => 0, 'mock_hint' => 'decide']
        ));

        $parsed = SkillSupport::extractJson($result['text']) ?? [];
        $decision = [
            'qualified'            => (bool) ($parsed['qualified'] ?? false),
            'closing_probability'  => max(0, min(100, (int) ($parsed['closing_probability'] ?? 25))),
            'lead_signals'         => array_values(array_filter((array) ($parsed['lead_signals'] ?? []), 'is_string')),
            'next_action'          => in_array($parsed['next_action'] ?? '', ['answer_directly', 'request_info', 'book_viewing', 'escalate'], true)
                ? $parsed['next_action'] : 'answer_directly',
            'send_photos_first'    => (bool) ($parsed['send_photos_first'] ?? false),
            'recommended_room_ids' => array_slice(array_map('intval', (array) ($parsed['recommended_room_ids'] ?? [])), 0, 3),
            'recommendation'       => (string) ($parsed['recommendation'] ?? ''),
            'reasoning'            => (string) ($parsed['reasoning'] ?? 'Model returned unparseable output; safe defaults used.'),
            'model'                => $result['model'],
            'rooms'                => $rooms,
            'memory_ids'           => $memory['ids'] ?? [],
        ];

        EpisodicLogger::log([
            'lead_id'     => (int) $lead['id'],
            'phase'       => $phase,
            'skill'       => 'decide',
            'model_used'  => $result['model'],
            'direction'   => 'internal',
            'intent'      => $understanding['intent'],
            'entities'    => [
                'next_action'          => $decision['next_action'],
                'qualified'            => $decision['qualified'],
                'closing_probability'  => $decision['closing_probability'],
                'send_photos_first'    => $decision['send_photos_first'],
                'recommended_room_ids' => $decision['recommended_room_ids'],
            ],
            'reasoning'   => $decision['reasoning'],
            'memory_used' => $decision['memory_ids'],
            'response_ms' => $ms,
        ]);

        return $decision;
    }
}
