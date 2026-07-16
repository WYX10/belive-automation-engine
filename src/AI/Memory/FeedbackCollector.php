<?php

declare(strict_types=1);

namespace App\AI\Memory;

use App\Core\Database;
use App\Models\Interaction;

/**
 * Self-learning step 1 — Feedback Collection. Three capture paths:
 *
 *  explicit  — admin "flag as incorrect" in chat history
 *  implicit  — per-message: customer corrects Eve, or repeats a question
 *  aggregate — cross-lead pattern detection (the Setapak case: conversations
 *              that die right after a price quote), run by cron/learning_job
 */
final class FeedbackCollector
{
    /** Minimum leads showing a pattern before it becomes feedback. */
    public const DROPOFF_MIN_LEADS = 3;
    /** Drop-off share that triggers the lesson (proposal example: 40%). */
    public const DROPOFF_RATE = 0.4;

    // ---------------------------------------------------------------- explicit

    public static function adminFlag(int $interactionId, string $errorType, string $comment): int
    {
        if (!in_array($errorType, FEEDBACK_ERROR_TYPES, true)) {
            $errorType = 'missed_intent';
        }

        $interaction = Interaction::find($interactionId);
        Interaction::flag($interactionId);

        $id = self::insert(
            $interactionId,
            $interaction['lead_id'] ?? null,
            'admin_flag',
            $errorType,
            $comment
        );

        EpisodicLogger::activity('feedback_admin_flag', null, null, $interaction['lead_id'] ?? null, "$errorType on interaction #$interactionId");

        return $id;
    }

    // ---------------------------------------------------------------- implicit

    /**
     * Per-message implicit signals, called right after UnderstandSkill.
     * Returns feedback ids created (ConversationManager feeds them straight
     * into LearningEngine so the loop is synchronous).
     *
     * @return int[]
     */
    public static function detectImplicit(int $leadId, array $understanding, array $history): array
    {
        $ids = [];

        // Customer directly corrects Eve ("that's wrong, the price is...").
        if ($understanding['intent'] === 'correction') {
            $message = (string) ($understanding['message'] ?? '');
            $errorType = match (true) {
                (bool) preg_match('/\b(price|rm\s?\d|harga|expensive|mahal)\b/i', $message)   => 'wrong_price',
                (bool) preg_match('/\b(available|availab|full|taken|kosong)\b/i', $message)   => 'wrong_availability',
                (bool) preg_match('/\b(rude|tone|kasar)\b/i', $message)                        => 'wrong_tone',
                default                                                                        => 'missed_intent',
            };

            $lastOutbound = self::lastOutbound($history);
            $ids[] = self::insert(
                $lastOutbound !== null ? (int) $lastOutbound['id'] : null,
                $leadId,
                'customer_correction',
                $errorType,
                'Customer correction: "' . mb_substr($message, 0, 300) . '"'
                . ($lastOutbound !== null ? ' — after Eve said: "' . mb_substr((string) $lastOutbound['message_out'], 0, 300) . '"' : '')
            );
        }

        // Customer had to ask the same thing twice → Eve missed it.
        if (self::isRepeatedQuestion($understanding, $history)) {
            $ids[] = self::insert(
                null,
                $leadId,
                'implicit_repeat',
                'missed_intent',
                'Customer repeated the same question (intent: ' . $understanding['intent'] . ') — previous answer did not land.'
            );
        }

        return $ids;
    }

    // --------------------------------------------------------------- aggregate

    /**
     * Cross-lead drop-off pattern detection (cron). For each area: of the
     * leads that received a price quote, how many went silent right after it?
     * At >= DROPOFF_RATE across >= DROPOFF_MIN_LEADS leads, file a
     * poor_sequencing feedback for that area — the Setapak lesson.
     *
     * @param float $quietHours how long a conversation must be silent to count as dropped
     * @return int[] feedback ids created
     */
    public static function detectDropoffPatterns(float $quietHours = 4.0): array
    {
        $rows = Database::run(
            "SELECT l.location AS area,
                    COUNT(DISTINCT l.id) AS priced_leads,
                    COUNT(DISTINCT CASE WHEN last.last_id = pq.max_pq_id
                                         AND last.last_at <= (NOW() - INTERVAL ? HOUR)
                                        THEN l.id END) AS dropped_leads
             FROM leads l
             JOIN (SELECT lead_id, MAX(id) AS max_pq_id
                   FROM ai_interactions
                   WHERE direction = 'outbound' AND message_kind = 'price_quote'
                   GROUP BY lead_id) pq ON pq.lead_id = l.id
             JOIN (SELECT lead_id, MAX(id) AS last_id, MAX(created_at) AS last_at
                   FROM ai_interactions
                   WHERE direction IN ('inbound', 'outbound')
                   GROUP BY lead_id) last ON last.lead_id = l.id
             WHERE l.location IS NOT NULL AND l.location <> ''
             GROUP BY l.location",
            [$quietHours]
        )->fetchAll();

        $ids = [];
        foreach ($rows as $row) {
            $priced = (int) $row['priced_leads'];
            $dropped = (int) $row['dropped_leads'];
            if ($priced < self::DROPOFF_MIN_LEADS || $dropped / $priced < self::DROPOFF_RATE) {
                continue;
            }

            $area = $row['area'];
            $pct = (int) round($dropped / $priced * 100);

            // One open pattern per area — don't re-file while unprocessed or
            // already learned.
            $existing = Database::run(
                "SELECT id FROM ai_feedback
                 WHERE feedback_source = 'pattern_detection' AND error_type = 'poor_sequencing'
                   AND comment LIKE ? ORDER BY id DESC LIMIT 1",
                ["%area: $area%"]
            )->fetch();
            if ($existing !== false) {
                continue;
            }

            $ids[] = self::insert(
                null,
                null,
                'pattern_detection',
                'poor_sequencing',
                "Drop-off pattern detected — area: $area. $dropped of $priced leads ($pct%) went silent "
                . "immediately after receiving a price quote. Hypothesis: quoting price before showing "
                . "the room loses engagement; photos-first should be tried for $area enquiries."
            );

            EpisodicLogger::activity('dropoff_pattern_detected', 'conversion', null, null, "$area: $dropped/$priced ($pct%)");
        }

        return $ids;
    }

    // ------------------------------------------------------------------ shared

    private static function insert(?int $interactionId, ?int $leadId, string $source, string $errorType, string $comment): int
    {
        Database::run(
            'INSERT INTO ai_feedback (interaction_id, lead_id, feedback_source, error_type, comment) VALUES (?, ?, ?, ?, ?)',
            [$interactionId, $leadId, $source, $errorType, $comment]
        );

        return (int) Database::pdo()->lastInsertId();
    }

    private static function lastOutbound(array $history): ?array
    {
        foreach (array_reverse($history) as $row) {
            if ($row['direction'] === 'outbound') {
                return $row;
            }
        }

        return null;
    }

    private static function isRepeatedQuestion(array $understanding, array $history): bool
    {
        if (in_array($understanding['intent'], ['smalltalk', 'other', 'correction'], true)) {
            return false;
        }

        // Same intent asked in the previous inbound message, with an Eve
        // reply in between → the answer didn't land.
        $inbounds = array_values(array_filter($history, fn ($r) => $r['direction'] === 'inbound'));
        $previous = end($inbounds);
        if ($previous === false) {
            return false;
        }

        return ($previous['intent'] ?? '') === $understanding['intent']
            && count($history) > count($inbounds); // at least one Eve reply exists
    }
}
