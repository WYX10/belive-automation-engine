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
  "reasoning": string          // one sentence: why this rule follows from the feedback
}

Rules:
- "sequencing" = about the ORDER of what Eve sends (photos before price, ask budget before recommending).
- "fact" = a concrete correction (a price, availability, address).
- The learned_rule must be self-contained and actionable without any other context.
- Never invent specifics that are not in the feedback.
PROMPT;

    /**
     * Distill one feedback row into a learned rule. Returns the
     * ai_learned_memory id, or null if the feedback was empty/duplicate.
     */
    public static function processFeedback(int $feedbackId): ?int
    {
        $feedback = Database::run('SELECT * FROM ai_feedback WHERE id = ?', [$feedbackId])->fetch();
        if ($feedback === false || (int) $feedback['processed'] === 1) {
            return null;
        }

        $context = self::buildContext($feedback);

        $client = ModelRouter::clientForPhase('conversion');
        [$result, $ms] = SkillSupport::timed(fn () => $client->generate(
            self::SYSTEM,
            [['role' => 'user', 'content' => $context]],
            ['max_tokens' => 400, 'temperature' => 0, 'mock_hint' => 'learn']
        ));

        $parsed = SkillSupport::extractJson($result['text']);
        if ($parsed === null || trim((string) ($parsed['learned_rule'] ?? '')) === '') {
            // Real failure surfaces as a logged error — never a faked rule.
            EpisodicLogger::activity('rule_learning_failed', 'conversion', $result['model'], $feedback['lead_id'] ? (int) $feedback['lead_id'] : null, 'Model output unparseable for feedback #' . $feedbackId);

            return null;
        }

        $memoryId = MemoryStore::saveRule(
            (string) ($parsed['context_tag'] ?? 'general'),
            (string) ($parsed['rule_type'] ?? 'fact'),
            (string) $parsed['learned_rule'],
            $feedbackId
        );

        Database::run('UPDATE ai_feedback SET processed = 1 WHERE id = ?', [$feedbackId]);

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
