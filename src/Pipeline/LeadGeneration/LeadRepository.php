<?php

declare(strict_types=1);

namespace App\Pipeline\LeadGeneration;

use App\Core\Database;

/**
 * Read-side queries for the admin lead views and dashboard — keeps listing/
 * filter SQL out of the page scripts.
 */
final class LeadRepository
{
    /** Filterable list for admin/leads. */
    public static function list(?string $status = null, ?string $channel = null, int $limit = 100): array
    {
        $sql = 'SELECT l.*,
                       (SELECT COUNT(*) FROM ai_interactions i
                         WHERE i.lead_id = l.id AND i.direction IN ("inbound","outbound")) AS message_count,
                       (SELECT COUNT(*) FROM bookings b WHERE b.lead_id = l.id) AS booking_count
                FROM leads l WHERE 1=1';
        $params = [];

        if ($status !== null && in_array($status, LEAD_STATUSES, true)) {
            $sql .= ' AND l.status = ?';
            $params[] = $status;
        }
        if ($channel !== null && in_array($channel, LEAD_SOURCE_CHANNELS, true)) {
            $sql .= ' AND l.source_channel = ?';
            $params[] = $channel;
        }

        return Database::run($sql . ' ORDER BY l.last_contact_at DESC, l.id DESC LIMIT ' . (int) $limit, $params)->fetchAll();
    }

    /** Counts per status + per channel for the filter chips. */
    public static function stats(): array
    {
        $byStatus = [];
        foreach (Database::run('SELECT status, COUNT(*) AS n FROM leads GROUP BY status')->fetchAll() as $row) {
            $byStatus[$row['status']] = (int) $row['n'];
        }

        $byChannel = [];
        foreach (Database::run('SELECT source_channel, COUNT(*) AS n FROM leads GROUP BY source_channel')->fetchAll() as $row) {
            $byChannel[$row['source_channel']] = (int) $row['n'];
        }

        return ['by_status' => $byStatus, 'by_channel' => $byChannel, 'total' => array_sum($byStatus)];
    }
}
