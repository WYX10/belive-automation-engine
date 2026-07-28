<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use DateTimeImmutable;

/**
 * digital_agreements — the generated tenancy agreement plus the stage it has
 * reached on its round trip: admin draft → landlord particulars and signature
 * → admin check → tenant signature. access_code is what the tenant uses to
 * open theirs.
 */
final class DigitalAgreement extends BaseModel
{
    protected const TABLE = 'digital_agreements';

    /** Stages in the order the document travels through them. */
    public const STAGES = ['draft', 'owner_review', 'admin_review', 'tenant_review', 'completed'];

    /**
     * Days left that make a tenancy "ending soon". One number, two consumers:
     * the timeline badge below and the window in which an owner may offer a
     * renewal price (App\Renewals\RenewalOfferManager) — so the badge and the
     * offer button can never disagree about when a tenancy is running out.
     */
    public const ENDING_SOON_DAYS = 30;

    public const STAGE_LABELS = [
        'draft'         => 'AI draft — with admin',
        'owner_review'  => 'With owner — details & signature',
        'admin_review'  => 'Returned — admin final check',
        'tenant_review' => 'With tenant — review & sign',
        'completed'     => 'Signed by both parties',
        'cancelled'     => 'Cancelled',
    ];

    /** Who the document is sitting with right now. */
    public const STAGE_OWNERS = [
        'draft'         => 'admin',
        'owner_review'  => 'owner',
        'admin_review'  => 'admin',
        'tenant_review' => 'tenant',
        'completed'     => 'nobody',
        'cancelled'     => 'nobody',
    ];

    public static function findByAccessCode(string $code): ?array
    {
        return self::first(['access_code' => strtoupper(trim($code))]);
    }

    public static function forLead(int $leadId): array
    {
        return self::all(['lead_id' => $leadId]);
    }

    /**
     * The agreement a tenant may open: only once admin has released it to them.
     * Anything earlier is still being prepared and is none of their business yet.
     */
    public static function visibleToTenant(int $leadId): ?array
    {
        $row = Database::run(
            "SELECT * FROM digital_agreements
             WHERE lead_id = ? AND status IN ('tenant_review', 'completed')
             ORDER BY id DESC LIMIT 1",
            [$leadId]
        )->fetch();

        return $row ?: null;
    }

    /** Agreements routed to one owner-portal login, newest first. */
    public static function forOwner(string $ownerName): array
    {
        return Database::run(
            'SELECT a.*, l.name AS tenant_name, l.wa_phone, r.name AS room_name, r.property_name, r.location
             FROM digital_agreements a
             JOIN leads l ON l.id = a.lead_id
             LEFT JOIN rooms r ON r.id = a.room_id
             WHERE a.owner_name = ?
             ORDER BY a.id DESC',
            [$ownerName]
        )->fetchAll();
    }

    /**
     * The owner's live tenancies: who is renting, where, and for how long.
     *
     * Only a completed agreement counts — anything earlier is a document still
     * being signed, not a tenancy — and only one carrying both dates, since a
     * rental period with no end is nothing to show a countdown for. Ordered by
     * the date they end, so whoever is closest to leaving comes first.
     *
     * The room's house comes from property_units and its development from
     * properties; rooms.property_name is the fallback for a room imported
     * before the hierarchy existed.
     */
    public static function tenanciesForOwner(string $ownerName): array
    {
        return Database::run(
            "SELECT a.*, l.name AS tenant_name, l.wa_phone,
                    r.name AS room_name, r.room_code, r.location, r.status AS room_status,
                    COALESCE(p.name, r.property_name) AS property_name,
                    u.name AS house_name
             FROM digital_agreements a
             JOIN leads l ON l.id = a.lead_id
             LEFT JOIN rooms r ON r.id = a.room_id
             LEFT JOIN properties p ON p.id = r.property_id
             LEFT JOIN property_units u ON u.id = r.unit_id
             WHERE a.owner_name = ?
               AND a.status = 'completed'
               AND a.starts_on IS NOT NULL
               AND a.ends_on IS NOT NULL
             ORDER BY a.ends_on ASC, a.id ASC",
            [$ownerName]
        )->fetchAll();
    }

    /** One agreement with the tenant/room context every screen needs. */
    public static function withContext(int $id): ?array
    {
        $row = Database::run(
            'SELECT a.*, l.name AS tenant_name, l.wa_phone, l.move_in_date,
                    r.name AS room_name, r.property_name, r.location, r.room_type, r.address
             FROM digital_agreements a
             JOIN leads l ON l.id = a.lead_id
             LEFT JOIN rooms r ON r.id = a.room_id
             WHERE a.id = ? LIMIT 1',
            [$id]
        )->fetch();

        return $row ?: null;
    }

    /** @return array<string, int> stage => count, for the admin queue header */
    public static function stageCounts(): array
    {
        $counts = array_fill_keys([...self::STAGES, 'cancelled'], 0);
        foreach (Database::run('SELECT status, COUNT(*) AS n FROM digital_agreements GROUP BY status') as $row) {
            $counts[$row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Tenants who confirmed a room (a confirmed or completed booking against a
     * specific room) and have no live agreement yet — the admin's start queue.
     */
    public static function awaitingGeneration(): array
    {
        return Database::run(
            "SELECT b.lead_id, b.room_id, MIN(b.viewing_datetime) AS confirmed_at,
                    l.name AS tenant_name, l.wa_phone, l.move_in_date, l.preferred_tenure,
                    r.name AS room_name, r.property_name, r.location, r.owner_name
             FROM bookings b
             JOIN leads l ON l.id = b.lead_id
             JOIN rooms r ON r.id = b.room_id
             WHERE b.status IN ('confirmed', 'completed')
               AND NOT EXISTS (
                   SELECT 1 FROM digital_agreements a
                   WHERE a.lead_id = b.lead_id AND a.room_id = b.room_id AND a.status <> 'cancelled'
               )
             GROUP BY b.lead_id, b.room_id, l.name, l.wa_phone, l.move_in_date, l.preferred_tenure,
                      r.name, r.property_name, r.location, r.owner_name
             ORDER BY confirmed_at DESC"
        )->fetchAll();
    }

    /**
     * How far along the stage track the document is, for the progress UI.
     *
     * @return array{index:int, total:int, label:string, holder:string, cancelled:bool}
     */
    public static function stage(string $status): array
    {
        $index = array_search($status, self::STAGES, true);

        return [
            'index'     => $index === false ? 0 : (int) $index,
            'total'     => count(self::STAGES),
            'label'     => self::STAGE_LABELS[$status] ?? $status,
            'holder'    => self::STAGE_OWNERS[$status] ?? 'admin',
            'cancelled' => $status === 'cancelled',
        ];
    }

    /**
     * Build a deterministic tenant-facing status from structured agreement dates.
     *
     * @return array{
     *   state: string,
     *   starts_on: string,
     *   ends_on: string,
     *   days_remaining: int,
     *   days_until_start: int,
     *   days_since_end: int,
     *   progress_percent: int
     * }|null
     */
    public static function timeline(array $agreement, ?DateTimeImmutable $today = null): ?array
    {
        $start = self::parseDate($agreement['starts_on'] ?? null);
        $end = self::parseDate($agreement['ends_on'] ?? null);
        if ($start === null || $end === null || $end < $start) {
            return null;
        }

        $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $daysRemaining = max(0, (int) $today->diff($end)->days);
        $daysUntilStart = max(0, (int) $today->diff($start)->days);
        $daysSinceEnd = max(0, (int) $end->diff($today)->days);

        if ($today < $start) {
            $state = 'upcoming';
        } elseif ($today > $end) {
            $state = 'expired';
        } elseif ($today == $end) {
            $state = 'ending_today';
        } else {
            $state = $daysRemaining <= self::ENDING_SOON_DAYS ? 'ending_soon' : 'active';
        }

        $totalDays = max(1, (int) $start->diff($end)->days + 1);
        $elapsedDays = $today <= $start ? 0 : min($totalDays, (int) $start->diff($today)->days);

        return [
            'state' => $state,
            'starts_on' => $start->format('Y-m-d'),
            'ends_on' => $end->format('Y-m-d'),
            'days_remaining' => in_array($state, ['active', 'ending_soon'], true) ? $daysRemaining : 0,
            'days_until_start' => $state === 'upcoming' ? $daysUntilStart : 0,
            'days_since_end' => $state === 'expired' ? $daysSinceEnd : 0,
            'progress_percent' => (int) round(($elapsedDays / $totalDays) * 100),
        ];
    }

    private static function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
