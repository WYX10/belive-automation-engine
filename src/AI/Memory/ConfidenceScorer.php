<?php

declare(strict_types=1);

namespace App\AI\Memory;

use App\Core\Database;
use App\Models\LearnedMemory;

/**
 * Self-learning step 4 — Adaptation dynamics. Rules earn confidence when they
 * demonstrably work (customer keeps engaging after a rule-guided reply) and
 * lose it when contradicted by newer corrections. Below the threshold a rule
 * soft-deactivates: kept for the audit trail, no longer retrieved.
 */
final class ConfidenceScorer
{
    public static function reinforce(int $ruleId): void
    {
        LearnedMemory::reinforce($ruleId);
        EpisodicLogger::activity('rule_reinforced', null, null, null, "rule #$ruleId confidence increased");
    }

    public static function contradict(int $ruleId): void
    {
        LearnedMemory::contradict($ruleId);
        EpisodicLogger::activity('rule_contradicted', null, null, null, "rule #$ruleId confidence decreased");
    }

    /**
     * Success signal sweep (cron): an outbound message that used learned rules
     * counts as a success once the customer replied after it — reinforce every
     * rule that shaped that message. Each interaction is only counted once.
     */
    public static function evaluateUsageOutcomes(): int
    {
        $rows = Database::run(
            "SELECT o.id, o.lead_id, o.memory_used
             FROM ai_interactions o
             WHERE o.direction = 'outbound'
               AND o.memory_used IS NOT NULL AND o.memory_used <> '[]'
               AND o.flagged = 0
               AND EXISTS (SELECT 1 FROM ai_interactions i
                           WHERE i.lead_id = o.lead_id AND i.direction = 'inbound' AND i.id > o.id)
               AND NOT EXISTS (SELECT 1 FROM ai_activity_log a
                               WHERE a.action = 'rule_usage_scored' AND a.detail = CONCAT('interaction #', o.id))"
        )->fetchAll();

        $reinforced = 0;
        foreach ($rows as $row) {
            foreach ((array) json_decode($row['memory_used'] ?? '[]', true) as $ruleId) {
                self::reinforce((int) $ruleId);
                $reinforced++;
            }
            EpisodicLogger::activity('rule_usage_scored', null, null, (int) $row['lead_id'], 'interaction #' . $row['id']);
        }

        return $reinforced;
    }

    /**
     * Time decay (cron/memory_decay): rules unused for $staleDays lose a
     * little confidence, and anything under the threshold deactivates.
     */
    public static function decayStale(int $staleDays = 30, float $step = 0.05): int
    {
        $stale = Database::run(
            'SELECT id FROM ai_learned_memory
             WHERE active = 1
               AND COALESCE(last_used_at, created_at) < (NOW() - INTERVAL ? DAY)',
            [$staleDays]
        )->fetchAll();

        foreach ($stale as $row) {
            Database::run(
                'UPDATE ai_learned_memory SET confidence_score = GREATEST(0.0, confidence_score - ?) WHERE id = ?',
                [$step, $row['id']]
            );
        }

        return LearnedMemory::deactivateBelowThreshold();
    }
}
