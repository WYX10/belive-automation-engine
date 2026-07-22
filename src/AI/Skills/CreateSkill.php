<?php

declare(strict_types=1);

namespace App\AI\Skills;

use App\AI\Memory\EpisodicLogger;
use App\AI\ModelRouter;
use App\Models\Room;

/**
 * Skill 3 — Creates (Content Generation): the actual reply text, and social
 * post captions for the content module. The system prompt is anchored to
 * BeLive's real brand voice (copy lines lifted from belive.asia) so Eve
 * sounds like BeLive's existing marketing, not a generic chatbot.
 */
final class CreateSkill
{
    private const VOICE = <<<VOICE
BRAND VOICE — you write like BeLive's own marketing. Tone anchors (real BeLive copy):
- "Fully furnished rooms. Zero deposit. Weekly cleaning. Just bring your bag — we handle the rest."
- "Find It. Book It. Live In It."
- "Live Smarter, Stay Better."
- "The Smarter Way to Rent"
Style rules: short punchy benefit-first phrasing; sentence case; em-dashes welcome; zero corporate filler ("kindly", "as per", "we are pleased to" are banned); warm and direct.
VOICE;

    private const REPLY_SYSTEM = self::VOICE . <<<PROMPT


You are Eve, BeLive's AI rental assistant on WhatsApp. Write the reply message.

Hard rules:
- WhatsApp style: 1–3 short sentences, or one sentence + a compact ✓ list. No markdown headers, no long paragraphs.
- Reply in the customer's language (English / Malay / Chinese — match what they used).
- Ground every fact in the provided room inventory. NEVER invent rooms, prices, or availability. If inventory says nothing matches, say so honestly and offer the closest alternative.
- NEVER state a price without naming its tenure ("RM 620/mo on a 12-month stay", not a bare "RM 620"). When recommending a tenure, mention the saving vs the flexible monthly rate. Zero deposit is BeLive's signature — mention it when introducing a room.
- Follow the decision given to you: if send_photos_first=true, do NOT state any price figure — tease the rooms, say photos are coming through, and ask if they'd like pricing after.
- Answer the question the customer actually asked, first. If they asked for the price, the reply opens with the price — never with another question.
- Read the CONVERSATION STATE. Never repeat an offer the customer already accepted ("would you like the pricing?" after they said yes), never re-announce photos that were already sent, and never re-ask a detail they already gave.
- If a recall line about a returning customer is provided, open with it naturally (reference their earlier enquiry specifically — never a generic "hi again"). Only ever do this once, at the start of a conversation — mid-conversation, just continue where you left off.
- If next_action=request_info, ask for exactly the one missing detail.
- If next_action=book_viewing, confirm the viewing details you were given.
- At most one emoji.

Return ONLY the reply text, nothing else.
PROMPT;

    private const CAPTION_SYSTEM = self::VOICE . <<<PROMPT


You write BeLive's social media captions. Convert the room metrics you are given into ONE ready-to-post caption for the stated platform.

Rules: hook first line; benefits as short phrases; end with a call to action to WhatsApp us; 3–6 relevant hashtags on the final line (e.g. #BeLive #RoomForRent + area tag). No invented facts — only what the metrics say. If an ADMIN BRIEF is given, follow its angle, tone and any campaign detail — but never let it override the no-invented-facts rule. Return ONLY the caption text.
PROMPT;

    /** @return array{text:string, model:string, interaction_id:int} */
    public static function reply(
        array $lead,
        array $understanding,
        array $decision,
        array $memory,
        ?string $recallLine = null,
        string $phase = 'conversion',
        array $history = []
    ): array {
        $client = ModelRouter::clientForPhase($phase);
        $state = SkillSupport::conversationState($history);

        $recommendedRooms = array_values(array_filter(
            $decision['rooms'],
            fn ($room) => $decision['recommended_room_ids'] === []
                || in_array((int) $room['id'], $decision['recommended_room_ids'], true)
        ));

        $prompt = implode("\n\n", array_filter([
            $memory['block'] ?? '',
            SkillSupport::historyBlock($history),
            $state['block'],
            $recallLine !== null ? "RETURNING CUSTOMER RECALL (use this to open):\n$recallLine" : '',
            'CUSTOMER: ' . json_encode([
                'name'     => $lead['name'],
                'language' => $understanding['language'],
                'intent'   => $understanding['intent'],
                'entities' => $understanding['entities'],
            ], JSON_UNESCAPED_UNICODE),
            'DECISION TO EXECUTE: ' . json_encode([
                'next_action'        => $decision['next_action'],
                'send_photos_first'  => $decision['send_photos_first'],
                'recommended_tenure' => $decision['recommended_tenure'] ?? null,
                'recommendation'     => $decision['recommendation'],
            ], JSON_UNESCAPED_UNICODE),
            "ROOMS TO OFFER:\n" . Room::promptBlock($recommendedRooms),
            'CUSTOMER MESSAGE: ' . ($understanding['message'] ?? ''),
        ]));

        [$result, $ms] = SkillSupport::timed(fn () => $client->generate(
            self::REPLY_SYSTEM,
            [['role' => 'user', 'content' => $prompt]],
            ['max_tokens' => 400, 'temperature' => 0.4, 'mock_hint' => 'create']
        ));

        $interactionId = EpisodicLogger::log([
            'lead_id'     => (int) $lead['id'],
            'phase'       => $phase,
            'skill'       => 'create',
            'model_used'  => $result['model'],
            'direction'   => 'internal',
            'reasoning'   => 'Drafted reply per decision (' . $decision['next_action']
                . ($decision['send_photos_first'] ? ', photos before price' : '') . ').',
            'memory_used' => $memory['ids'] ?? [],
            'response_ms' => $ms,
        ]);

        return ['text' => trim($result['text']), 'model' => $result['model'], 'interaction_id' => $interactionId];
    }

    /**
     * Social caption from live room metrics. $brief is the admin's own steer
     * ("what should this post be about?") from the content studio.
     * @return array{text:string, model:string}
     */
    public static function socialCaption(array $room, string $platform, ?string $brief = null): array
    {
        $client = ModelRouter::clientForPhase('content_creation');

        $roomId = (int) $room['id'];
        $prices = Room::prices($roomId);
        $metrics = [
            'platform'          => $platform,
            'room'              => $room['property_name'] ?: $room['name'],
            'area'              => $room['location'],
            'room_type'         => $room['room_type'],
            'price_rm_monthly'  => $prices['monthly']['price'] ?? null,
            'price_rm_12_month' => $prices['12_month']['price'] ?? null,
            'deposit_rm'        => (float) ($room['deposit_amount'] ?? 0),
            'features'          => Room::amenities($roomId),
        ];

        $brief = $brief !== null ? trim($brief) : '';
        $prompt = implode("\n\n", array_filter([
            'ROOM METRICS: ' . json_encode($metrics, JSON_UNESCAPED_UNICODE),
            $brief !== '' ? "ADMIN BRIEF (what this post should be about):\n" . mb_substr($brief, 0, 1000) : '',
        ]));

        [$result, $ms] = SkillSupport::timed(fn () => $client->generate(
            self::CAPTION_SYSTEM,
            [['role' => 'user', 'content' => $prompt]],
            ['max_tokens' => 350, 'temperature' => 0.6, 'mock_hint' => 'caption']
        ));

        EpisodicLogger::log([
            'phase'       => 'content_creation',
            'skill'       => 'create',
            'model_used'  => $result['model'],
            'direction'   => 'internal',
            'message_out' => $result['text'],
            'message_kind' => 'social_caption',
            'reasoning'   => "Caption generated for $platform from room #{$room['id']} metrics."
                . ($brief !== '' ? ' Admin brief applied.' : ''),
            'response_ms' => $ms,
        ]);

        return ['text' => trim($result['text']), 'model' => $result['model']];
    }
}
