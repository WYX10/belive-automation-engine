<?php

declare(strict_types=1);

namespace App\AI;

/**
 * ⚠ LOCAL TESTING ONLY — this is an offline stub, not an AI model.
 *
 * Returned by ModelRouter exclusively when MOCK_AI=true in .env, so the
 * pipeline (webhook → skills → memory → booking) can be exercised end-to-end
 * on a machine with no API keys. It answers from crude keyword heuristics and
 * labels itself in every response.
 *
 * It must NEVER be enabled for the judge demo: the demo runs on real models
 * via real keys entered in Admin → API Credentials. See docs/setup_guide.md.
 *
 * Skills pass opts['mock_hint'] naming the response shape they expect
 * ('understand' | 'decide' | 'create' | 'learn' | 'caption' | 'time_parse');
 * real clients ignore that field entirely.
 */
final class MockClient implements LlmClient
{
    public function __construct(private readonly string $model = 'mock-offline-stub')
    {
    }

    public function generate(string $system, array $messages, array $opts = []): array
    {
        $lastUser = '';
        foreach (array_reverse($messages) as $m) {
            if ($m['role'] === 'user') {
                $lastUser = $m['content'];
                break;
            }
        }

        $text = match ($opts['mock_hint'] ?? '') {
            'understand' => $this->mockUnderstand($lastUser),
            'decide'     => $this->mockDecide($lastUser),
            'create'     => $this->mockCreate($lastUser),
            'learn'      => $this->mockLearn($system . ' ' . $lastUser),
            'caption'    => "[MOCK] Fully furnished room, ready when you are. Just bring your bag — we handle the rest.",
            'time_parse' => $this->mockTimeParse($lastUser),
            'scam'       => $this->mockScam($lastUser),
            'agreement'  => $this->mockAgreement($lastUser),
            default      => "[MOCK REPLY — offline stub, not an AI model] Received: " . mb_substr($lastUser, 0, 120),
        };

        return ['text' => $text, 'raw' => ['mock' => true], 'model' => $this->model];
    }

    public function modelName(): string
    {
        return $this->model;
    }

    private function mockUnderstand(string $msg): string
    {
        // The prompt carries conversation history for context — heuristics
        // must only read the new message, never keywords from old turns.
        if (($pos = strrpos($msg, 'NEW CUSTOMER MESSAGE:')) !== false) {
            $msg = trim(substr($msg, $pos + strlen('NEW CUSTOMER MESSAGE:')));
        }
        $lower = mb_strtolower($msg);

        $location = null;
        foreach (['setapak', 'cheras', 'sepang', 'sri kembangan', 'sentul', 'kuala lumpur', 'petaling jaya', 'batu kawan', 'johor'] as $area) {
            if (str_contains($lower, $area)) {
                $location = ucwords($area);
                break;
            }
        }

        $budget = null;
        if (preg_match('/rm\s?(\d{3,5})/i', $msg, $m) || preg_match('/\b(\d{3,4})\b/', $msg, $m)) {
            $budget = (int) $m[1];
        }

        $intent = 'general_enquiry';
        if (preg_match('/\b(book|viewing|visit|appointment|tomorrow|tonight|am|pm)\b/i', $msg)) {
            $intent = 'booking_request';
        } elseif ($location !== null || $budget !== null || str_contains($lower, 'room')) {
            $intent = 'room_enquiry';
        }
        if (preg_match('/\b(photo|picture|pic|image)\b/i', $msg)) {
            $intent = 'photo_request';
        }
        if (preg_match('/\b(price|how much|rent|rental)\b/i', $msg) && $intent === 'general_enquiry') {
            $intent = 'price_enquiry';
        }

        $profile = preg_match('/\b(student|college|university|uni|tarumt)\b/i', $msg) ? 'student'
            : (preg_match('/\b(work|working|professional|job|office)\b/i', $msg) ? 'working_professional' : null);

        return json_encode([
            'intent'          => $intent,
            'entities'        => [
                'location'     => $location,
                'budget'       => $budget,
                'move_in_date' => preg_match('/\b(july|august|september|next month|asap)\b/i', $msg, $d) ? $d[1] : null,
                'room_type'    => preg_match('/\b(master|medium|small|single|studio)\b/i', $msg, $r) ? strtolower($r[1]) : null,
            ],
            'tenant_profile'  => $profile,
            'language'        => 'en',
            'reasoning'       => '[MOCK] Keyword heuristics only — offline stub, not a real model.',
        ], JSON_UNESCAPED_UNICODE);
    }

    private function mockDecide(string $userPrompt): string
    {
        // Honour an injected learned rule ONLY — the mock scans the LEARNED
        // RULES block in the user prompt, never the static system prompt, so
        // behaviour visibly changes offline exactly when a rule was learned.
        $photosFirst = self::hasPhotosFirstRule($userPrompt);
        $isBooking = str_contains($userPrompt, '"intent":"booking_request"');

        return json_encode([
            'qualified'           => true,
            'closing_probability' => $isBooking ? 85 : 62,
            'lead_signals'        => ['asked about a specific area', 'gave a budget'],
            'next_action'         => $isBooking ? 'book_viewing' : 'answer_directly',
            'send_photos_first'   => $photosFirst,
            'recommendation'      => $photosFirst
                ? 'Send room photos before quoting the price (learned rule in effect).'
                : 'Answer with matching rooms and price.',
            'reasoning'           => '[MOCK] Heuristic decision — offline stub, not a real model.',
        ], JSON_UNESCAPED_UNICODE);
    }

    private function mockCreate(string $userPrompt): string
    {
        $photosFirst = self::hasPhotosFirstRule($userPrompt)
            || str_contains($userPrompt, '"send_photos_first":true');

        // Returning customer: acknowledge the recall context like the real
        // model is instructed to (reference the prior enquiry specifically).
        $recall = '';
        if (preg_match('/RETURNING CUSTOMER RECALL.*?looked for: ([^;]+); in area: ([^;]+)/s', $userPrompt, $m)) {
            $recall = '[MOCK] Welcome back! Still looking for ' . trim($m[1]) . ' in ' . trim($m[2]) . ', or has your search changed? ';
        }

        return $recall . ($photosFirst
            ? "[MOCK] Here are photos of the room first 📷 — fully furnished, WiFi, weekly cleaning. Want the pricing details?"
            : "[MOCK] Fully furnished room, zero deposit, weekly cleaning. Rental is RM 650/month. Want photos or a viewing?");
    }

    private function mockLearn(string $context): string
    {
        $isSequencing = (bool) preg_match('/drop-?off|sequenc|photo|engag/i', $context);

        $tag = 'general';
        if (preg_match('/\b(Setapak|Cheras|Sepang|Sri Kembangan|Sentul|Kuala Lumpur|Petaling Jaya|Batu Kawan|Johor)\b/i', $context, $m)) {
            $tag = ucwords(strtolower($m[1]));
        }

        return json_encode([
            'context_tag'  => $tag,
            'rule_type'    => $isSequencing ? 'sequencing' : 'fact',
            'learned_rule' => $isSequencing
                ? "For $tag enquiries, send room photos before quoting the price — price-first replies correlate with drop-off."
                : "Corrected fact for $tag enquiries (see source feedback for detail).",
            'reasoning'    => '[MOCK] Pattern keywords only — offline stub, not a real model.',
        ], JSON_UNESCAPED_UNICODE);
    }

    private function mockScam(string $prompt): string
    {
        // Flag only what the provided market stats justify: strongly-negative
        // deviation = suspicious-cheap; otherwise clean.
        $flags = [];
        if (preg_match('/"deviation_pct":\s*(-\d+(?:\.\d+)?)/', $prompt, $m) && (float) $m[1] < -25) {
            $flags[] = [
                'pattern'  => 'price_far_below_market',
                'severity' => 'high',
                'detail'   => "[MOCK] Listed {$m[1]}% below comparable average — classic bait-listing signal.",
            ];
        }
        if (str_contains($prompt, '"address":"(none given)"')) {
            $flags[] = [
                'pattern'  => 'missing_fixed_address',
                'severity' => 'low',
                'detail'   => '[MOCK] No concrete address on the listing.',
            ];
        }

        return json_encode(['flags' => $flags, 'reasoning' => '[MOCK] Heuristic screen — offline stub, not a real model.'], JSON_UNESCAPED_UNICODE);
    }

    private function mockAgreement(string $prompt): string
    {
        $get = function (string $key) use ($prompt): string {
            return preg_match('/"' . $key . '":"([^"]*)"/', $prompt, $m) ? $m[1] : '—';
        };
        $rm = preg_match('/"monthly_rm":(\d+(?:\.\d+)?)/', $prompt, $m) ? $m[1] : '—';

        return "[MOCK AGREEMENT — offline stub]\n\n1. Parties: BeLive (landlord's agent) and {$get('tenant_name')} ({$get('tenant_phone')}).\n"
            . "2. The room: {$get('room')}, {$get('area')} ({$get('room_type')} room).\n"
            . "3. Monthly rental: RM $rm, including furnishings, WiFi and weekly cleaning.\n"
            . "4. Deposit: zero deposit — BeLive standard.\n"
            . "5. House rules: no smoking indoors; respect quiet hours 11pm–7am.\n"
            . "6. Cleaning: weekly common-area cleaning included.\n"
            . "7. Notice period: 30 days written notice either side.\n"
            . "8. This document is acknowledged digitally with a typed name and timestamp.";
    }

    /** True iff the prompt carries an injected LEARNED RULES block with a photos-before-price rule. */
    private static function hasPhotosFirstRule(string $prompt): bool
    {
        if (!preg_match('/LEARNED RULES.*?(?=\n\n[A-Z]|$)/s', $prompt, $m)) {
            return false;
        }

        return (bool) preg_match('/photos?[^\n]*(before|first)[^\n]*(pric|quot)/i', $m[0]);
    }

    private function mockTimeParse(string $msg): string
    {
        $base = new \DateTimeImmutable('tomorrow 15:00');
        if (preg_match('/(\d{1,2})\s*(am|pm)/i', $msg, $m)) {
            $hour = (int) $m[1] % 12 + (strtolower($m[2]) === 'pm' ? 12 : 0);
            $base = $base->setTime($hour, 0);
        }

        return json_encode([
            'datetime'  => $base->format('Y-m-d H:i:s'),
            'confident' => true,
            'reasoning' => '[MOCK] Regex time parse — offline stub, not a real model.',
        ]);
    }
}
