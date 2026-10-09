<?php

declare(strict_types=1);

namespace App\AI\Memory;

use App\Models\LearnedMemory;
use App\Core\Database;
use InvalidArgumentException;
use RuntimeException;

/**
 * Write side of the learned-rule store. Only LearningEngine (and tests)
 * write rules; everything else reads through MemoryRetriever.
 */
final class MemoryStore
{
    /**
     * Reuse normalized text, a canonical key or a validated same-scope model
     * match. The feedback link and learning write commit together.
     */
    public static function saveRule(
        string $contextTag,
        string $ruleType,
        string $learnedRule,
        ?int $sourceFeedbackId = null,
        float $confidence = MEMORY_CONFIDENCE_DEFAULT,
        ?string $lessonKey = null,
        ?int $existingRuleId = null
    ): int {
        $contextTag = mb_substr(MemoryRetriever::normalizeTag($contextTag) ?? 'general', 0, 60);
        $learnedRule = trim($learnedRule);
        if ($learnedRule === '') {
            throw new InvalidArgumentException('A learned lesson cannot be empty.');
        }
        $lessonKey = $lessonKey !== null && preg_match('/^[a-z0-9_-]{1,160}$/', $lessonKey) ? $lessonKey : null;

        if (!in_array($ruleType, MEMORY_RULE_TYPES, true)) {
            $ruleType = 'fact';
        }

        // Serialize lookup + insert across PHP workers, including paraphrases.
        $lock = 'lesson:' . substr(hash('sha256', mb_strtolower($contextTag) . ':' . $ruleType), 0, 48);
        if (!Database::acquireLock($lock, 5)) {
            throw new RuntimeException('Learning store busy; feedback remains queued.');
        }
        $pdo = Database::pdo();
        $ownsTransaction = !$pdo->inTransaction();
        try {
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }
            $rules = LearnedMemory::all(['context_tag' => $contextTag, 'rule_type' => $ruleType], 'id ASC');
            foreach ($rules as $existing) {
                $sameText = self::sameLesson($existing['learned_rule'], $learnedRule);
                $sameKey = $lessonKey !== null && $existing['lesson_key'] === $lessonKey;
                $sameMeaning = $existingRuleId === (int) $existing['id'];
                // Numeric corrections must not collapse into a different fact.
                if (($sameKey || $sameMeaning) && !$sameText && $ruleType === 'fact') {
                    preg_match_all('/\d+(?:\.\d+)?/', $existing['learned_rule'], $oldNumbers);
                    preg_match_all('/\d+(?:\.\d+)?/', $learnedRule, $newNumbers);
                    if ($oldNumbers[0] !== $newNumbers[0]) {
                        $sameKey = $sameMeaning = false;
                        $lessonKey = null;
                    }
                }
                if ($sameText || $sameKey || $sameMeaning) {
                    // Retired/contradicted lessons remain retired; a duplicate is not proof.
                    if ((int) $existing['active'] === 1) {
                        LearnedMemory::reinforce((int) $existing['id']);
                    }
                    return self::complete((int) $existing['id'], $sourceFeedbackId, $ownsTransaction);
                }
            }
            $id = LearnedMemory::create([
                'context_tag' => $contextTag, 'rule_type' => $ruleType,
                'learned_rule' => $learnedRule, 'source_feedback_id' => $sourceFeedbackId,
                'confidence_score' => max(0, min(1, $confidence)), 'active' => 1,
                'lesson_key' => $lessonKey,
                'lesson_hash' => LessonIdentity::hash($contextTag, $ruleType, $learnedRule, $lessonKey),
            ]);
            return self::complete($id, $sourceFeedbackId, $ownsTransaction);
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            Database::releaseLock($lock);
        }
    }

    private static function complete(int $memoryId, ?int $feedbackId, bool $ownsTransaction): int
    {
        if ($feedbackId !== null) {
            Database::run('UPDATE ai_feedback SET processed = 1, memory_id = ? WHERE id = ?', [$memoryId, $feedbackId]);
        }
        if ($ownsTransaction) {
            Database::pdo()->commit();
        }
        return $memoryId;
    }

    /** Contradiction path: decay an existing rule that a new correction disputes. */
    public static function contradictRule(int $ruleId): void
    {
        LearnedMemory::contradict($ruleId);
    }

    private static function sameLesson(string $a, string $b): bool
    {
        return LessonIdentity::normalize($a) === LessonIdentity::normalize($b);
    }
}
