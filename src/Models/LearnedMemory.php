<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * ai_learned_memory — distilled, reusable rules produced by LearningEngine.
 * Rules may be factual corrections OR sequencing/strategy lessons (the
 * proposal's Setapak example is rule_type='sequencing'). Retrieval always
 * filters active=1 and the confidence threshold; deactivated rules are kept
 * for the audit trail, never hard-deleted.
 */
final class LearnedMemory extends BaseModel
{
    protected const TABLE = 'ai_learned_memory';

    /** Rules eligible for prompt injection for a given context tag. */
    public static function activeForContext(?string $contextTag, int $limit = 10): array
    {
        $sql = 'SELECT * FROM ai_learned_memory
                WHERE active = 1 AND confidence_score >= ?';
        $params = [MEMORY_CONFIDENCE_THRESHOLD];

        if ($contextTag !== null && $contextTag !== '') {
            // Context-specific rules plus global ones (context_tag = 'general').
            $sql .= " AND (context_tag = ? OR context_tag = 'general')";
            $params[] = $contextTag;
        } else {
            // Area still unknown. Only the global rules can apply — an
            // unfiltered query handed every area's rules to the prompt, so a
            // Cheras price fact or a Setapak sequencing rule steered a
            // conversation that had nothing to do with either.
            $sql .= " AND context_tag = 'general'";
        }

        $sql .= ' ORDER BY confidence_score DESC, times_reinforced DESC LIMIT ' . (int) $limit;

        return Database::run($sql, $params)->fetchAll();
    }

    public static function markUsed(int $id): void
    {
        self::update($id, ['last_used_at' => date('Y-m-d H:i:s')]);
    }

    public static function reinforce(int $id, float $step = MEMORY_REINFORCE_STEP): void
    {
        Database::run(
            'UPDATE ai_learned_memory
             SET confidence_score = LEAST(1.0, confidence_score + ?),
                 times_reinforced = times_reinforced + 1
             WHERE id = ?',
            [$step, $id]
        );
    }

    public static function contradict(int $id, float $step = MEMORY_DECAY_STEP): void
    {
        Database::run(
            'UPDATE ai_learned_memory
             SET confidence_score = GREATEST(0.0, confidence_score - ?),
                 times_contradicted = times_contradicted + 1
             WHERE id = ?',
            [$step, $id]
        );
        self::deactivateBelowThreshold();
    }

    /** Soft-deactivate (audit trail preserved) once confidence collapses. */
    public static function deactivateBelowThreshold(): int
    {
        return Database::run(
            'UPDATE ai_learned_memory SET active = 0
             WHERE active = 1 AND confidence_score < ?',
            [MEMORY_CONFIDENCE_THRESHOLD]
        )->rowCount();
    }
}
