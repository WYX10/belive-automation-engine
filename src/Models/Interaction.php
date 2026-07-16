<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * ai_interactions — the episodic record every AI skill call writes to
 * (via EpisodicLogger). Feeds the Learning Log, per-lead memory, and the
 * dashboard's reply/response-time stats.
 */
final class Interaction extends BaseModel
{
    protected const TABLE = 'ai_interactions';

    public static function forLead(int $leadId, int $limit = 20): array
    {
        return self::all(['lead_id' => $leadId], 'id DESC', $limit);
    }

    /** Chronological conversation transcript (outbound replies + inbound). */
    public static function transcript(int $leadId, int $limit = 50): array
    {
        $rows = Database::run(
            "SELECT * FROM ai_interactions
             WHERE lead_id = ? AND direction IN ('inbound','outbound')
             ORDER BY id DESC LIMIT " . (int) $limit,
            [$leadId]
        )->fetchAll();

        return array_reverse($rows);
    }

    public static function repliesToday(): int
    {
        return (int) Database::run(
            "SELECT COUNT(*) FROM ai_interactions
             WHERE direction = 'outbound' AND DATE(created_at) = CURDATE()"
        )->fetchColumn();
    }

    public static function totalReplies(): int
    {
        return self::count(['direction' => 'outbound']);
    }

    public static function avgResponseMs(): ?int
    {
        $avg = Database::run(
            "SELECT AVG(response_ms) FROM ai_interactions
             WHERE direction = 'outbound' AND response_ms IS NOT NULL"
        )->fetchColumn();

        return $avg === null ? null : (int) round((float) $avg);
    }

    public static function flag(int $id): bool
    {
        return self::update($id, ['flagged' => 1]);
    }
}
