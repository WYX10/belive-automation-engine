<?php

declare(strict_types=1);

namespace App\AI\Memory;

use App\Models\LearnedMemory;

/**
 * Write side of the learned-rule store. Only LearningEngine (and tests)
 * write rules; everything else reads through MemoryRetriever.
 */
final class MemoryStore
{
    /**
     * Persist a distilled rule. If an active rule with the same context_tag
     * and rule_type already covers the same lesson (exact text match), it is
     * reinforced instead of duplicated.
     */
    public static function saveRule(
        string $contextTag,
        string $ruleType,
        string $learnedRule,
        ?int $sourceFeedbackId = null,
        float $confidence = MEMORY_CONFIDENCE_DEFAULT
    ): int {
        $contextTag = MemoryRetriever::normalizeTag($contextTag) ?? 'general';

        if (!in_array($ruleType, MEMORY_RULE_TYPES, true)) {
            $ruleType = 'fact';
        }

        $existing = LearnedMemory::first([
            'context_tag'  => $contextTag,
            'rule_type'    => $ruleType,
            'active'       => 1,
        ]);

        if ($existing !== null && self::sameLesson($existing['learned_rule'], $learnedRule)) {
            LearnedMemory::reinforce((int) $existing['id']);

            return (int) $existing['id'];
        }

        return LearnedMemory::create([
            'context_tag'        => $contextTag,
            'rule_type'          => $ruleType,
            'learned_rule'       => trim($learnedRule),
            'source_feedback_id' => $sourceFeedbackId,
            'confidence_score'   => $confidence,
            'active'             => 1,
        ]);
    }

    /** Contradiction path: decay an existing rule that a new correction disputes. */
    public static function contradictRule(int $ruleId): void
    {
        LearnedMemory::contradict($ruleId);
    }

    private static function sameLesson(string $a, string $b): bool
    {
        $norm = static fn (string $s) => preg_replace('/\s+/', ' ', mb_strtolower(trim($s)));

        return $norm($a) === $norm($b);
    }
}
