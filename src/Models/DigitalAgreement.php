<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeImmutable;

/**
 * digital_agreements — generated tenancy agreement text + the typed-name
 * acknowledgement state. access_code is what the tenant uses to open theirs.
 */
final class DigitalAgreement extends BaseModel
{
    protected const TABLE = 'digital_agreements';

    public static function findByAccessCode(string $code): ?array
    {
        return self::first(['access_code' => strtoupper(trim($code))]);
    }

    public static function forLead(int $leadId): array
    {
        return self::all(['lead_id' => $leadId]);
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
            $state = $daysRemaining <= 30 ? 'ending_soon' : 'active';
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
