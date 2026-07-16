<?php

declare(strict_types=1);

namespace App\AI\Memory;

use App\Models\LearnedMemory;

/**
 * Read side of the learned-rule store. Fetches active, confident rules for a
 * context tag and formats them for prompt injection — this is how a lesson
 * learned yesterday changes what Eve says today, without any code change.
 */
final class MemoryRetriever
{
    /** Active rules for a context (plus global 'general' rules). */
    public static function forContext(?string $contextTag, int $limit = 8): array
    {
        return LearnedMemory::activeForContext(self::normalizeTag($contextTag), $limit);
    }

    /**
     * Prompt block for DecideSkill / CreateSkill injection. Marks each rule
     * used (last_used_at) so the confidence loop can track live usage.
     *
     * @return array{block: string, ids: int[]}
     */
    public static function promptBlock(array $rules): array
    {
        if ($rules === []) {
            return ['block' => '', 'ids' => []];
        }

        $ids = [];
        $lines = [];
        foreach ($rules as $rule) {
            $ids[] = (int) $rule['id'];
            $lines[] = sprintf(
                '- [%s | confidence %.2f] %s',
                $rule['rule_type'],
                (float) $rule['confidence_score'],
                trim($rule['learned_rule'])
            );
            LearnedMemory::markUsed((int) $rule['id']);
        }

        $block = "LEARNED RULES (from Eve's self-learning memory — follow these; they override defaults):\n"
            . implode("\n", $lines);

        return ['block' => $block, 'ids' => $ids];
    }

    public static function normalizeTag(?string $tag): ?string
    {
        $tag = trim((string) $tag);

        return $tag === '' ? null : ucwords(strtolower($tag));
    }
}
