<?php

declare(strict_types=1);

namespace App\AI\Skills;

use App\AI\Memory\EpisodicLogger;
use App\AI\ModelRouter;
use App\Core\Settings;
use App\Integrations\WhatsApp\WhatsAppLink;
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
- If next_action=book_viewing, ask for one concrete day + time ("What day and time suit you?"). NEVER promise to "get back to you with the exact time" or "sort it out later" — Eve either confirms an exact slot immediately (a separate scheduling message handles that) or asks the customer for a concrete time now.
- At most one emoji.

Return ONLY the reply text, nothing else.
PROMPT;

    private const CAPTION_SYSTEM = self::VOICE . <<<PROMPT


You write BeLive's social media captions. Convert the room metrics you are given into ONE ready-to-post caption for the stated platform.

Rules: hook first line; benefits as short phrases; then a call to action to WhatsApp us that includes the WHATSAPP LINK you are given, copied character for character on its own line; 3–6 relevant hashtags on the final line, which MUST begin with #BeLiveSolopreneur and may then add others (#BeLive #RoomForRent + area tag). No invented facts — only what the metrics say. If an ADMIN BRIEF is given, follow its angle, tone and any campaign detail — but never let it override the no-invented-facts rule. Return ONLY the caption text.
PROMPT;

    private const VIDEO_SYSTEM = self::VOICE . <<<PROMPT


You script BeLive's vertical promo videos (Instagram Reels, TikTok, Facebook). You are given one room's real metrics and how many shots are available. Turn them into a short scene-by-scene script plus the caption the post ships with.

Return ONLY a JSON object — no prose, no markdown fence:
{"scenes": [{"headline": "...", "sub": "...", "seconds": 3.5}], "caption": "..."}

Scene rules:
- 3 to 5 scenes. Each scene is one shot of the room with your words burned onto it.
- headline: at most 32 characters. A hook or a benefit, not a sentence. This is the big line.
- sub: at most 48 characters, the supporting line under it. Use "" when the shot is stronger without one.
- seconds: between 2.5 and 5. The scenes together must land between 12 and 20 seconds.
- Scene 1 has to stop a thumb — lead with the single most tempting fact you were given.
- Do NOT write a closing call-to-action scene: a branded WhatsApp end card is added after your last scene.
- Never invent a fact. A price always carries its tenure ("RM 620/mo, 12 months"), and zero deposit is claimed only when the metrics say the deposit is 0.

Caption rules: exactly what you'd write as the post's caption — hook first line, benefits as short phrases, the WHATSAPP LINK you are given copied character for character on its own line, then 3–6 hashtags whose first is #BeLiveSolopreneur. If an ADMIN BRIEF is given, follow its angle in both the scenes and the caption.
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

        $metrics = self::roomMetrics($room, $platform);

        // The whole point of a post is the tap that follows it, so the caption
        // ships with the link that opens a WhatsApp chat with Eve — prefilled
        // with the room, so her first reply already knows what they saw.
        $whatsappLink = self::captionWhatsappLink($room, $platform);

        $brief = $brief !== null ? trim($brief) : '';
        $prompt = implode("\n\n", array_filter([
            'ROOM METRICS: ' . json_encode($metrics, JSON_UNESCAPED_UNICODE),
            "WHATSAPP LINK (include verbatim in the call to action):\n$whatsappLink",
            $brief !== '' ? "ADMIN BRIEF (what this post should be about):\n" . mb_substr($brief, 0, 1000) : '',
        ]));

        [$result, $ms] = SkillSupport::timed(fn () => $client->generate(
            self::CAPTION_SYSTEM,
            [['role' => 'user', 'content' => $prompt]],
            ['max_tokens' => 350, 'temperature' => 0.6, 'mock_hint' => 'caption']
        ));

        $result['text'] = self::withCampaignHashtag(self::withWhatsappLink(trim($result['text']), $whatsappLink));

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

    /**
     * The script for a vertical promo video of one room — the scenes that get
     * burned onto the shots, plus the caption the post ships with. Same live
     * metrics as socialCaption, same admin brief, same guaranteed WhatsApp link.
     *
     * The model is asked for JSON, but a promo video is never allowed to fail
     * on a malformed answer: an unusable reply falls back to a script built
     * straight from the room's own metrics, and the fallback is labelled in the
     * returned model name so the studio never claims more than it did.
     *
     * @param int $shotCount how many distinct shots the composer can draw on
     * @return array{caption:string, scenes:array<int, array{headline:string, sub:string, seconds:float}>, model:string}
     */
    public static function videoPromo(array $room, string $platform, ?string $brief = null, int $shotCount = 1): array
    {
        $client = ModelRouter::clientForPhase('content_creation');

        $metrics = self::roomMetrics($room, $platform);
        $whatsappLink = self::captionWhatsappLink($room, $platform);
        $brief = $brief !== null ? trim($brief) : '';

        $prompt = implode("\n\n", array_filter([
            'ROOM METRICS: ' . json_encode($metrics, JSON_UNESCAPED_UNICODE),
            'SHOTS AVAILABLE: ' . max(1, $shotCount) . ' (a shot may be reused if you write more scenes than there are shots)',
            "WHATSAPP LINK (include verbatim in the caption's call to action):\n$whatsappLink",
            $brief !== '' ? "ADMIN BRIEF (what this video should be about):\n" . mb_substr($brief, 0, 1000) : '',
        ]));

        [$result, $ms] = SkillSupport::timed(fn () => $client->generate(
            self::VIDEO_SYSTEM,
            [['role' => 'user', 'content' => $prompt]],
            ['max_tokens' => 700, 'temperature' => 0.6, 'mock_hint' => 'video']
        ));

        $parsed = self::parseVideoScript($result['text']);
        $scenes = $parsed['scenes'] !== [] ? $parsed['scenes'] : self::fallbackScenes($metrics);
        $model = $parsed['scenes'] !== [] ? $result['model'] : $result['model'] . ' (fallback script)';

        $caption = self::withCampaignHashtag(self::withWhatsappLink(
            $parsed['caption'] !== '' ? $parsed['caption'] : self::fallbackCaption($metrics),
            $whatsappLink
        ));

        EpisodicLogger::log([
            'phase'        => 'content_creation',
            'skill'        => 'create',
            'model_used'   => $result['model'],
            'direction'    => 'internal',
            'message_out'  => $caption,
            'message_kind' => 'video_script',
            'reasoning'    => sprintf(
                'Promo video script (%d scenes, %.1fs) for %s from room #%d metrics.%s%s',
                count($scenes),
                array_sum(array_column($scenes, 'seconds')),
                $platform,
                (int) $room['id'],
                $brief !== '' ? ' Admin brief applied.' : '',
                $parsed['scenes'] === [] ? ' Model returned no usable scenes — metrics fallback used.' : ''
            ),
            'response_ms' => $ms,
        ]);

        return ['caption' => $caption, 'scenes' => $scenes, 'model' => $model];
    }

    /**
     * The grounding block both content skills share: only facts that exist in
     * inventory, prices carrying their tenure.
     *
     * @return array<string, mixed>
     */
    private static function roomMetrics(array $room, string $platform): array
    {
        $roomId = (int) $room['id'];
        $prices = Room::prices($roomId);

        return [
            'platform'          => $platform,
            'room'              => $room['property_name'] ?: $room['name'],
            'area'              => $room['location'],
            'room_type'         => $room['room_type'],
            'price_rm_monthly'  => $prices['monthly']['price'] ?? null,
            'price_rm_12_month' => $prices['12_month']['price'] ?? null,
            'deposit_rm'        => (float) ($room['deposit_amount'] ?? 0),
            'features'          => Room::amenities($roomId),
        ];
    }

    /**
     * Read the model's JSON back into scenes the composer can render. Anything
     * out of contract is clamped rather than trusted — an over-long headline
     * would overflow the frame and a 40-second scene would break the reel.
     *
     * @return array{caption:string, scenes:array<int, array{headline:string, sub:string, seconds:float}>}
     */
    private static function parseVideoScript(string $raw): array
    {
        // Models like to wrap JSON in a ```json fence even when told not to.
        $text = trim($raw);
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $text = $m[0];
        }
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            return ['caption' => '', 'scenes' => []];
        }

        $scenes = [];
        foreach ((array) ($decoded['scenes'] ?? []) as $scene) {
            if (!is_array($scene)) {
                continue;
            }
            $headline = trim((string) ($scene['headline'] ?? ''));
            if ($headline === '') {
                continue;
            }
            $scenes[] = [
                'headline' => mb_substr($headline, 0, 40),
                'sub'      => mb_substr(trim((string) ($scene['sub'] ?? '')), 0, 60),
                'seconds'  => min(
                    CONTENT_VIDEO_SCENE_SECONDS['max'],
                    max(CONTENT_VIDEO_SCENE_SECONDS['min'], (float) ($scene['seconds'] ?? 3.5))
                ),
            ];
            if (count($scenes) === CONTENT_VIDEO_SCENE_MAX) {
                break;
            }
        }

        return ['caption' => trim((string) ($decoded['caption'] ?? '')), 'scenes' => $scenes];
    }

    /**
     * A script assembled from the metrics alone, for when the model's answer
     * cannot be used. It says less than a written script would — but every line
     * of it is a fact the inventory already holds.
     *
     * @param array<string, mixed> $metrics
     * @return array<int, array{headline:string, sub:string, seconds:float}>
     */
    private static function fallbackScenes(array $metrics): array
    {
        $area = (string) ($metrics['area'] ?? '');
        $price = $metrics['price_rm_12_month'] ?? $metrics['price_rm_monthly'] ?? null;
        $tenure = $metrics['price_rm_12_month'] !== null ? '12 months' : 'flexible monthly';
        $features = array_slice((array) ($metrics['features'] ?? []), 0, 3);

        $scenes = [[
            'headline' => mb_substr(ucfirst((string) $metrics['room_type']) . ' room' . ($area !== '' ? " in $area" : ''), 0, 40),
            'sub'      => 'Fully furnished — just bring your bag',
            'seconds'  => 3.5,
        ]];

        if ($price !== null) {
            $scenes[] = [
                'headline' => 'RM ' . number_format((float) $price) . '/mo',
                'sub'      => "On a $tenure stay",
                'seconds'  => 3.5,
            ];
        }
        if ((float) ($metrics['deposit_rm'] ?? 0) === 0.0) {
            $scenes[] = ['headline' => 'Zero deposit', 'sub' => 'Move in without the upfront hit', 'seconds' => 3.0];
        }
        if ($features !== []) {
            $scenes[] = [
                'headline' => 'What comes with it',
                'sub'      => mb_substr(implode(' · ', $features), 0, 60),
                'seconds'  => 4.0,
            ];
        }

        return array_slice($scenes, 0, CONTENT_VIDEO_SCENE_MAX);
    }

    /** @param array<string, mixed> $metrics */
    private static function fallbackCaption(array $metrics): string
    {
        $price = $metrics['price_rm_12_month'] ?? $metrics['price_rm_monthly'] ?? null;

        return implode("\n", array_filter([
            ucfirst((string) $metrics['room_type']) . ' room in ' . (string) $metrics['area'] . ' — ready when you are.',
            $price !== null
                ? 'RM ' . number_format((float) $price) . '/mo on a '
                    . ($metrics['price_rm_12_month'] !== null ? '12-month' : 'flexible monthly') . ' stay.'
                : '',
            'Fully furnished. Weekly cleaning. Just bring your bag.',
        ]));
    }

    /**
     * The tap-to-chat link a caption carries, prefilled with the room being
     * advertised. Admins can restyle the wording in the content studio's
     * settings; {room}, {area} and {platform} are filled in here.
     */
    public static function captionWhatsappLink(array $room, string $platform): string
    {
        return WhatsAppLink::to(strtr(
            Settings::get('content_wa_prefill', 'Hi beLive! I saw your {platform} post about {room} in {area} — is it still available?'),
            [
                '{room}'     => (string) ($room['name'] ?? 'a room'),
                '{area}'     => (string) ($room['location'] ?? ''),
                '{platform}' => ucfirst($platform),
            ]
        ));
    }

    /**
     * The model is asked for the link, but a caption without one is a dead end
     * for every reader — so a missing link is appended rather than trusted.
     */
    private static function withWhatsappLink(string $caption, string $link): string
    {
        if (str_contains($caption, $link)) {
            return $caption;
        }

        return $caption . "\n\n💬 WhatsApp us: " . $link;
    }

    /**
     * The campaign tag is a submission requirement, not a stylistic choice, so
     * it is checked on the way out rather than left to the model — the same
     * treatment the WhatsApp link gets, and for the same reason: a post that
     * goes out without it cannot be fixed after the fact.
     *
     * A caption that already ends in hashtags gets it joined onto that line;
     * anything else gets it on a line of its own.
     */
    private static function withCampaignHashtag(string $caption): string
    {
        $caption = rtrim($caption);
        if (stripos($caption, CONTENT_REQUIRED_HASHTAG) !== false) {
            return $caption;
        }

        $lines = explode("\n", $caption);
        $last = trim((string) end($lines));
        if ($last !== '' && str_starts_with($last, '#')) {
            $lines[count($lines) - 1] = CONTENT_REQUIRED_HASHTAG . ' ' . $last;

            return implode("\n", $lines);
        }

        return $caption . "\n\n" . CONTENT_REQUIRED_HASHTAG;
    }
}
