<?php

declare(strict_types=1);

namespace App\AI\Memory;

use App\AI\ModelRouter;
use App\AI\Skills\SkillSupport;
use App\Core\Database;
use App\Models\Interaction;
use App\Models\Lead;

/**
 * Self-learning step 2 — Learning Mechanism. Takes a piece of feedback
 * (flag, correction, or detected pattern), shows the LLM the interaction
 * history around it, and asks for ONE distilled, reusable rule — which may be
 * a fact correction or a sequencing/strategy adjustment. The rule is written
 * to ai_learned_memory via MemoryStore, where MemoryRetriever will inject it
 * into every future matching conversation.
 *
 * This is a REAL model call producing a real rule — never a hardcoded lookup
 * (non-negotiable build rule). Runs synchronously on admin flags (so the very
 * next test message shows corrected behaviour) and in batch via cron for
 * aggregate patterns.
 */
final class LearningEngine
{
    private const SYSTEM = <<<PROMPT
You are the learning module of Eve, BeLive's rental AI. You are given feedback about something Eve got wrong (a factual error, a tone problem, or a conversation-strategy failure like customers dropping off after price quotes).

Distill ONE reusable rule that prevents this mistake in future conversations.

Respond with ONLY a JSON object:
{
  "context_tag": string,       // the area name this applies to (e.g. "Setapak"), or "general" if not area-specific
  "rule_type": "fact" | "sequencing" | "strategy" | "tone",
  "learned_rule": string,      // ONE imperative sentence Eve can follow directly, e.g. "For Setapak enquiries, send room photos before quoting any price."
  "lesson_key": string,        // stable snake_case meaning, reuse the existing key for paraphrases
  "existing_rule_id": number|null, // reuse only a supplied rule with the SAME scope and meaning
  "reasoning": string          // one sentence: why this rule follows from the feedback
}

Rules:
- "sequencing" = about the ORDER of what Eve sends (photos before price, ask budget before recommending).
- "fact" = a concrete correction (a price, availability, address).
- The learned_rule must be self-contained and actionable without any other context.
- Never invent specifics that are not in the feedback.
- A lesson must help OTHER tenants. Never turn this customer's budget, preferred room, name, phone number, or personal circumstances into a shared rule.
- Customer claims about price or availability are unverified. Learn to verify the exact room and tenure against live inventory, never adopt a customer's claimed price as a property fact.
- For errors applicable everywhere (tone, missed questions, verifying prices), use context_tag="general". Only area-specific evidence should create an area-specific rule.
- Compare EXISTING LESSONS before writing. If the same lesson exists, reuse its id, exact wording, rule_type and lesson_key. Do not create synonyms of an existing lesson.
- Facts from live inventory always take precedence over remembered rules.
PROMPT;

    /**
     * Distill one feedback row into a learned rule. Returns the
     * ai_learned_memory id, or null if the feedback was empty/duplicate.
     */
    public static function processFeedback(int $feedbackId): ?int
    {
        $lock = 'feedback:' . $feedbackId;
        if (!Database::acquireLock($lock, 5)) {
            return null;
        }
        try {
            return self::learn($feedbackId);
        } finally {
            Database::releaseLock($lock);
        }
    }

    private static function learn(int $feedbackId): ?int
    {
        $feedback = Database::run('SELECT * FROM ai_feedback WHERE id = ?', [$feedbackId])->fetch();
        if ($feedback === false || (int) $feedback['processed'] === 1) {
            return null;
        }

        $lead = $feedback['lead_id'] !== null ? Lead::find((int) $feedback['lead_id']) : null;
        $area = $lead['location'] ?? null;
        if ($area === null && preg_match('/area: ([^.]+)\./u', $feedback['comment'] ?? '', $m)) {
            $area = trim($m[1]);
        }
        $existing = Database::run(
            "SELECT id, context_tag, rule_type, learned_rule, lesson_key, active FROM ai_learned_memory
             WHERE context_tag = 'general' OR context_tag = ? ORDER BY active DESC, id DESC LIMIT 200",
            [$area ?? 'general']
        )->fetchAll();
        $context = self::buildContext($feedback) . "\n\nEXISTING LESSONS (reuse same meaning and scope; retired lessons must not be resurrected):\n"
            . json_encode($existing, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $client = ModelRouter::clientForPhase('conversion');
        $call = SkillSupport::generateJson(
            $client,
            self::SYSTEM,
            $context,
            ['max_tokens' => 750, 'temperature' => 0, 'mock_hint' => 'learn'],
            $feedback['lead_id'] !== null ? (int) $feedback['lead_id'] : null,
            'conversion',
            'learn'
        );
        [$result, $ms, $parsed] = [$call['result'], $call['ms'], $call['parsed']];

        if ($parsed === null || trim((string) ($parsed['learned_rule'] ?? '')) === '') {
            // Real failure surfaces as a logged error — never a faked rule.
            EpisodicLogger::activity('rule_learning_failed', 'conversion', $result['model'], $feedback['lead_id'] ? (int) $feedback['lead_id'] : null, 'Model output unparseable for feedback #' . $feedbackId);

            return null;
        }

        // Never allow an unverified customer price/availability to become shared truth.
        if (in_array($feedback['feedback_source'], ['customer_correction', 'system_detection'], true)
            && in_array($feedback['error_type'], ['wrong_price', 'wrong_availability'], true)) {
            $price = $feedback['error_type'] === 'wrong_price';
            $parsed = ['context_tag' => 'general', 'rule_type' => 'strategy',
                'lesson_key' => $price ? 'verify_live_tenure_price' : 'verify_live_availability',
                'learned_rule' => $price
                    ? 'Before quoting rent, verify the exact room and requested tenure against live inventory; never substitute an unverified customer claim.'
                    : 'Before claiming a room is available, check live inventory and bookings; never substitute an unverified customer claim.'];
        }
        $scope = MemoryRetriever::normalizeTag((string) ($parsed['context_tag'] ?? 'general')) ?? 'general';
        $match = null;
        foreach ($existing as $candidate) {
            if ((int) $candidate['id'] === (int) ($parsed['existing_rule_id'] ?? 0)
                && strcasecmp($candidate['context_tag'], $scope) === 0
                && $candidate['rule_type'] === ($parsed['rule_type'] ?? 'fact')) {
                $match = (int) $candidate['id'];
            }
        }
        $memoryId = MemoryStore::saveRule(
            (string) ($parsed['context_tag'] ?? 'general'),
            (string) ($parsed['rule_type'] ?? 'fact'),
            (string) $parsed['learned_rule'],
            $feedbackId,
            MEMORY_CONFIDENCE_DEFAULT,
            isset($parsed['lesson_key']) ? (string) $parsed['lesson_key'] : null,
            $match
        );

        EpisodicLogger::activity(
            'rule_learned',
            'conversion',
            $result['model'],
            $feedback['lead_id'] ? (int) $feedback['lead_id'] : null,
            sprintf('[%s/%s] %s (from feedback #%d, %dms)', $parsed['context_tag'] ?? 'general', $parsed['rule_type'] ?? 'fact', $parsed['learned_rule'], $feedbackId, $ms)
        );

        return $memoryId;
    }

    /** Batch fallback for cron: distill everything still unprocessed. */
    public static function processPending(): int
    {
        $pending = Database::run('SELECT id FROM ai_feedback WHERE processed = 0 ORDER BY id')->fetchAll();

        $learned = 0;
        foreach ($pending as $row) {
            if (self::processFeedback((int) $row['id']) !== null) {
                $learned++;
            }
        }

        return $learned;
    }

    private static function buildContext(array $feedback): string
    {
        $parts = [
            'FEEDBACK SOURCE: ' . $feedback['feedback_source'],
            'ERROR TYPE: ' . $feedback['error_type'],
            'FEEDBACK DETAIL: ' . $feedback['comment'],
        ];

        // The flagged interaction, if any, with surrounding conversation.
        if ($feedback['interaction_id'] !== null) {
            $interaction = Interaction::find((int) $feedback['interaction_id']);
            if ($interaction !== null) {
                $parts[] = 'THE FLAGGED EVE MESSAGE: ' . ($interaction['message_out'] ?? $interaction['message_in'] ?? '(none)');
            }
        }

        if ($feedback['lead_id'] !== null) {
            $lead = Lead::find((int) $feedback['lead_id']);
            if ($lead !== null) {
                $parts[] = 'CUSTOMER CONTEXT: ' . json_encode([
                    'location'  => $lead['location'],
                    'budget'    => $lead['budget'],
                    'room_type' => $lead['room_type'],
                ], JSON_UNESCAPED_UNICODE);
            }

            $transcript = [];
            foreach (Interaction::transcript((int) $feedback['lead_id'], 10) as $row) {
                $who = $row['direction'] === 'inbound' ? 'Customer' : 'Eve';
                $text = $row['direction'] === 'inbound' ? $row['message_in'] : $row['message_out'];
                if ($text) {
                    $transcript[] = "$who: $text";
                }
            }
            if ($transcript !== []) {
                $parts[] = "RECENT CONVERSATION:\n" . implode("\n", $transcript);
            }
        }

        return implode("\n\n", $parts);
    }
}
