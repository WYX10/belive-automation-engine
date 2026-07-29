<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use InvalidArgumentException;

/**
 * All six proposal lead channels flow into this one table, distinguished by
 * source_channel. wa_phone is the cross-channel identity key: the proposal's
 * "Memory" claim (Eve recalls a returning customer) hangs off it.
 */
final class Lead extends BaseModel
{
    protected const TABLE = 'leads';

    public static function findByPhone(string $waPhone): ?array
    {
        return self::first(['wa_phone' => $waPhone]);
    }

    /**
     * Identity resolution for inbound contact: returns the existing lead for
     * this number (repeat customer → memory kicks in) or creates a new one.
     */
    public static function findOrCreate(string $waPhone, ?string $name, string $sourceChannel): array
    {
        $existing = self::findByPhone($waPhone);
        if ($existing !== null) {
            $updates = ['last_contact_at' => date('Y-m-d H:i:s')];
            if ($name !== null && $name !== '' && empty($existing['name'])) {
                $updates['name'] = $name;
            }
            self::update((int) $existing['id'], $updates);

            return array_merge($existing, $updates, ['is_returning' => true]);
        }

        if (!in_array($sourceChannel, LEAD_SOURCE_CHANNELS, true)) {
            throw new InvalidArgumentException("Unknown source channel: $sourceChannel");
        }

        $id = self::create([
            'wa_phone'        => $waPhone,
            'name'            => $name,
            'source_channel'  => $sourceChannel,
            'status'          => 'new',
            'last_contact_at' => date('Y-m-d H:i:s'),
        ]);

        return array_merge(self::find($id), ['is_returning' => false]);
    }

    /** Validated status transition (new → qualified → converted, no skipping back). */
    public static function transition(int $id, string $status): bool
    {
        if (!in_array($status, LEAD_STATUSES, true)) {
            throw new InvalidArgumentException("Unknown lead status: $status");
        }

        $lead = self::find($id);
        if ($lead === null) {
            return false;
        }

        $rank = array_flip(LEAD_STATUSES); // new=0, qualified=1, converted=2
        if ($rank[$status] < $rank[$lead['status']]) {
            return false; // never demote a lead automatically
        }

        return self::update($id, ['status' => $status]);
    }

    /** Merge freshly-extracted enquiry details without clobbering known ones. */
    public static function mergeEnquiryDetails(int $id, array $details): void
    {
        $lead = self::find($id);
        if ($lead === null) {
            return;
        }

        $updates = [];
        foreach (['location', 'budget', 'move_in_date', 'room_type'] as $field) {
            $incoming = trim((string) ($details[$field] ?? ''));
            if ($incoming !== '' && strcasecmp($incoming, (string) $lead[$field]) !== 0) {
                $updates[$field] = $incoming;
            }
        }

        // A tenure the customer states themselves always wins.
        $tenure = $details['tenure'] ?? null;
        if (in_array($tenure, ['monthly', '6_month', '12_month'], true) && $tenure !== $lead['preferred_tenure']) {
            $updates['preferred_tenure'] = $tenure;
        }

        if ($updates !== []) {
            self::update($id, $updates);
        }
    }

    public static function setAiAssessment(int $id, int $closingProbability, array $signals, string $recommendation): void
    {
        self::update($id, [
            'closing_probability' => max(0, min(100, $closingProbability)),
            'lead_signals'        => json_encode($signals, JSON_UNESCAPED_UNICODE),
            'ai_recommendation'   => $recommendation,
        ]);
    }

    /** Calendar month to date — a day's count is too spiky to steer on. */
    public static function capturedThisMonth(): int
    {
        return (int) Database::run(
            "SELECT COUNT(*) FROM leads WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
        )->fetchColumn();
    }

    /**
     * The dashboard's "top 10 live leads": ranked by how likely Eve thinks each
     * one is to close, so the hottest lead sits at the top rather than merely
     * the chattiest. Leads Eve has not scored yet fall to the bottom — an
     * unscored lead is unknown, not promising — and recency breaks ties, which
     * is what keeps a live conversation ahead of a stale one on equal odds.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function hottest(int $limit = 10): array
    {
        return Database::run(
            'SELECT * FROM leads
              ORDER BY closing_probability IS NULL, closing_probability DESC,
                       last_contact_at IS NULL, last_contact_at DESC, id DESC
              LIMIT ' . max(1, $limit)
        )->fetchAll();
    }
}
