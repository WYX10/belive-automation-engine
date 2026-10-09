<?php

declare(strict_types=1);

namespace App\AI\Skills;

use App\AI\Memory\EpisodicLogger;
use App\AI\ModelRouter;
use App\AI\TenantMarketing;
use App\Catalog\RoomRecommender;
use App\Models\Lead;
use App\Models\Room;
use App\Models\TenantRequirement;

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
  "send_photos_first": boolean,       // OPENER only: show the room BEFORE any price figure
  "send_photos": boolean,             // attach room photos to this reply (customer asked for them, or said yes to them)
  "recommended_room_ids": number[],   // ids from the inventory list, best first, max 3
  "recommended_tenure": "monthly" | "6_month" | "12_month" | null,  // which commitment fits THIS person
  "recommendation": string,           // one line for the admin dashboard
  "reasoning": string                 // 1-2 sentences: why this decision
}

Decision guidance:
- Situational matching: students → budget/single rooms; working professionals → middle/master/premium. Different situations get different answers, never a fixed script.
- Tenure is situational too: a student on a multi-year course → 12_month (best value); someone on rotation/short assignment → monthly (flexibility premium); unsure or medium-term → 6_month. Recommend the tenure that fits their stated situation, and say why in reasoning. Prices are per tenure — never reason about a price without naming its tenure.
- "book_viewing" when the customer proposes or agrees to a time/viewing.
- "escalate" for complaints, legal/payment disputes, or anything Eve should not answer alone.
- "request_info" only when a genuinely needed detail (like area) is missing.
- If a LEARNED RULE applies to this context, you MUST follow it (e.g. a sequencing rule that says send photos before price ⇒ send_photos_first=true) and mention it in reasoning.

The two photo flags are different things — read the CONVERSATION STATE before setting either:
- "send_photos_first" is a ONE-TIME opener: show the room before quoting a price. If photos have already been sent, or the customer has already asked for pricing, it MUST be false — the sequencing rule is already satisfied, and re-teasing loops.
- "send_photos" is the plain answer to "can I see the room?": set it true whenever the customer asked for photos, said yes to an offer of photos, or is still waiting on photos Eve mentioned. It stays true even after pricing has been discussed — a photo request is not cancelled by a price question.
- NEVER say photos are coming without setting a photo flag. If neither flag is true, the reply must not mention sending photos at all.

Never loop:
- If the customer has asked for pricing (or agreed to Eve's offer to share it), next_action MUST be "answer_directly" with send_photos_first=false. Answer the question they actually asked; never re-offer something they already accepted.
- Only use "request_info" for a detail the customer has not already given anywhere in the history.
- Saved tenant requirements outrank demographic guesses. Never recommend a longer tenure as a match when the tenant explicitly wants a short stay.
- Use the marketing plan to explain relevant verified benefits and disclose requirement mismatches. Inventory facts outrank learned rules and customer assertions.
PROMPT;

    /**
     * @return array{qualified:bool, closing_probability:int, lead_signals:array, next_action:string,
     *               send_photos_first:bool, send_photos:bool, recommended_room_ids:array,
     *               recommendation:string, reasoning:string, model:string, rooms:array, memory_ids:array}
     */
    public static function run(array $lead, array $understanding, array $memory, string $phase = 'conversion', array $history = []): array
    {
        $client = ModelRouter::clientForPhase($phase);

        $entities = $understanding['entities'];
        $candidates = RoomRecommender::candidates($lead, $understanding);
        $rooms = $candidates['rooms'];
        $state = SkillSupport::conversationState($history);
        $requirements = $understanding['tenant_requirements'] ?? TenantRequirement::forLead((int) $lead['id']);
        $marketing = TenantMarketing::promptBlock($requirements, $rooms, $understanding['intent'], (int) $lead['id'], $understanding['objection'] ?? null);

        $prompt = implode("\n\n", array_filter([
            $memory['block'] ?? '',
            TenantRequirement::promptBlock($requirements),
            $marketing,
            SkillSupport::historyBlock($history),
            $state['block'],
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
            $candidates['block'],
        ]));

        $call = SkillSupport::generateJson(
            $client,
            self::SYSTEM,
            $prompt,
            ['max_tokens' => 900, 'temperature' => 0, 'mock_hint' => 'decide'],
            (int) $lead['id'],
            $phase,
            'decide'
        );
        [$result, $ms] = [$call['result'], $call['ms']];

        $parsed = $call['parsed'] ?? [];
        $usable = $call['parsed'] !== null;
        $decision = [
            'qualified'            => (bool) ($parsed['qualified'] ?? false),
            'closing_probability'  => max(0, min(100, (int) ($parsed['closing_probability'] ?? 25))),
            'lead_signals'         => array_values(array_filter((array) ($parsed['lead_signals'] ?? []), 'is_string')),
            'next_action'          => in_array($parsed['next_action'] ?? '', ['answer_directly', 'request_info', 'book_viewing', 'escalate'], true)
                ? $parsed['next_action'] : 'answer_directly',
            'send_photos_first'    => (bool) ($parsed['send_photos_first'] ?? false),
            'send_photos'          => (bool) ($parsed['send_photos'] ?? false),
            'recommended_room_ids' => array_slice(array_map('intval', (array) ($parsed['recommended_room_ids'] ?? [])), 0, 3),
            'recommended_tenure'   => in_array($parsed['recommended_tenure'] ?? '', Room::TENURES, true)
                ? $parsed['recommended_tenure'] : null,
            'recommendation'       => (string) ($parsed['recommendation'] ?? ''),
            'reasoning'            => (string) ($parsed['reasoning'] ?? 'Model returned unparseable output; safe defaults used.'),
            'model'                => $result['model'],
            'rooms'                => $rooms,
            'marketing_guidance'   => $marketing,
            // Rules only count as used when the model's answer actually came
            // back — otherwise the learning log fills up with rules credited
            // for decisions they had no hand in.
            'memory_ids'           => $usable ? ($memory['ids'] ?? []) : [],
        ];

        if (!empty($requirements['tenure'])) {
            $decision['recommended_tenure'] = $requirements['tenure'];
        }

        if (!$usable) {
            $decision['reasoning'] = 'Model returned unparseable output after a retry; the learned rules in'
                . ' this prompt could not be applied. Deterministic fallbacks used.';
            EpisodicLogger::activity(
                'decision_fallback',
                $phase,
                $result['model'],
                (int) $lead['id'],
                'DecideSkill fell back to defaults — ' . count($memory['ids'] ?? []) . ' learned rule(s) were skipped.'
            );
        }

        // Whatever else failed, there has to be a room to talk about (and to
        // attach photos to). The recommender already ranked the inventory for
        // this lead, so its top pick is a sound floor.
        $decision['recommended_room_ids'] = array_values(array_intersect($decision['recommended_room_ids'], array_map('intval', array_column($rooms, 'id'))));
        if ($decision['recommended_room_ids'] === [] && $rooms !== []) {
            $decision['recommended_room_ids'] = [(int) $rooms[0]['id']];
        }

        // Photos-first is a one-time opener, and a direct price question always
        // wins. Enforced in code, not left to the model: a learned sequencing
        // rule ("photos before price") otherwise fires on every single turn and
        // the customer never gets the price they keep asking for.
        if ($decision['send_photos_first'] && ($state['photos_sent'] || $state['price_asked']
            || $understanding['intent'] === 'price_enquiry')) {
            $decision['send_photos_first'] = false;
            $decision['reasoning'] .= $state['photos_sent']
                ? ' [Photos already sent earlier — not re-sending; answering the pending question instead.]'
                : ' [Customer asked for pricing directly — answering rather than teasing photos first.]';

            if ($decision['next_action'] === 'request_info') {
                $decision['next_action'] = 'answer_directly';
            }
        }

        // A photo REQUEST is not a sequencing tease, and suppressing the tease
        // must never swallow it. Decided in code, from the customer's own
        // words, because it has to hold even when the model's answer came back
        // unreadable or mis-labelled: whichever model the admin has assigned to
        // this phase, "show me the room" gets photos.
        //
        // Already-seen rooms are excluded by NAME, not by a single conversation
        // -wide flag — being shown one room is not an answer about another.
        $unseen = self::firstUnseenRoom($decision, $rooms, $state['photo_rooms'] ?? []);
        $asked = $understanding['intent'] === 'photo_request'
            || SkillSupport::asksForPhotos((string) ($understanding['message'] ?? ''))
            || $state['photos_wanted'];

        if (!$decision['send_photos'] && ($state['photos_promised'] || ($asked && $unseen !== null))) {
            $decision['send_photos'] = true;
            $decision['reasoning'] .= $state['photos_promised']
                ? ' [Photos were promised earlier and never sent — attaching them now.]'
                : ' [Customer asked to see the room — attaching photos with this reply.]';
        }
        // Show the room they have not seen, not the one they already have.
        if ($decision['send_photos'] && $unseen !== null) {
            $decision['recommended_room_ids'] = array_values(array_unique(
                array_merge([$unseen], $decision['recommended_room_ids'])
            ));
        }
        if ($decision['send_photos'] && $decision['next_action'] === 'request_info') {
            // Answer what they asked before asking for anything else.
            $decision['next_action'] = 'answer_directly';
        }

        // Persist the tenure Eve landed on (customer's own statement wins).
        if ($decision['recommended_tenure'] !== null && empty($lead['preferred_tenure'])) {
            Lead::update((int) $lead['id'], ['preferred_tenure' => $decision['recommended_tenure']]);
        }

        // Every recommendation is auditable: which model, which rooms, which tenure.
        if ($decision['recommended_room_ids'] !== [] || $decision['recommended_tenure'] !== null) {
            EpisodicLogger::activity(
                'room_recommendation',
                $phase,
                $result['model'],
                (int) $lead['id'],
                'rooms=[' . implode(',', $decision['recommended_room_ids']) . '] tenure=' . ($decision['recommended_tenure'] ?? 'n/a')
            );
        }

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
                'send_photos'          => $decision['send_photos'],
                'recommended_room_ids' => $decision['recommended_room_ids'],
                'recommended_tenure'   => $decision['recommended_tenure'],
            ],
            'reasoning'   => $decision['reasoning'],
            'memory_used' => $decision['memory_ids'],
            'response_ms' => $ms,
        ]);

        return $decision;
    }

    /**
     * The best-matching room this customer has not already been sent photos of,
     * recommendation order first. Null when they have seen everything on offer —
     * which is exactly when a re-send would be the looping bug, not a fix.
     *
     * @param string[] $seen room names already photographed to this customer
     */
    private static function firstUnseenRoom(array $decision, array $rooms, array $seen): ?int
    {
        $seen = array_map(static fn ($name) => mb_strtolower(trim((string) $name)), $seen);

        $ordered = [];
        foreach ($decision['recommended_room_ids'] as $id) {
            foreach ($rooms as $room) {
                if ((int) $room['id'] === (int) $id) {
                    $ordered[] = $room;
                }
            }
        }
        foreach ($rooms as $room) {
            $ordered[] = $room;
        }

        foreach ($ordered as $room) {
            if (!in_array(mb_strtolower(trim((string) $room['name'])), $seen, true)) {
                return (int) $room['id'];
            }
        }

        return null;
    }
}
