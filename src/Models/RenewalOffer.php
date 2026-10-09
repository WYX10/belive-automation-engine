<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * renewal_offers (migration 040) — the promotional rent an owner offers a
 * tenant whose term is running out, and the tenant's answer to it.
 *
 * Writes go through App\Renewals\RenewalOfferManager, which owns the rules.
 * This class is the read side plus the one derived value the schema does not
 * store: an offer past its expires_on is expired, worked out at read time so
 * nothing has to sweep the table.
 *
 * Reads are scoped by lead_id for a tenant and by owner_name for an owner —
 * never by room — so an offer only ever surfaces to the two people it is
 * between.
 */
final class RenewalOffer extends BaseModel
{
    protected const TABLE = 'renewal_offers';

    /** Still awaiting an answer: offered, and not yet past its expiry date. */
    public static function isOpen(array $offer): bool
    {
        return $offer['status'] === 'offered' && (string) $offer['expires_on'] >= date('Y-m-d');
    }

    /**
     * offered | expired | accepted | declined | withdrawn — what to badge it as.
     */
    public static function state(array $offer): string
    {
        if ($offer['status'] !== 'offered') {
            return (string) $offer['status'];
        }

        return self::isOpen($offer) ? 'offered' : 'expired';
    }

    /** What the tenant saves each month against what they pay now. */
    public static function monthlySaving(array $offer): float
    {
        return round((float) $offer['current_rent_rm'] - (float) $offer['promo_rent_rm'], 2);
    }

    /**
     * The offer a tenant may act on right now. The manager allows one open
     * offer per tenancy; a lead holding two tenancies at once could therefore
     * have two, so this returns the newest — the one their portal shows.
     */
    public static function openForTenant(int $leadId): ?array
    {
        $row = Database::run(
            "SELECT o.*, r.name AS room_name, r.room_code, r.location
             FROM renewal_offers o
             LEFT JOIN rooms r ON r.id = o.room_id
             WHERE o.lead_id = ? AND o.status = 'offered' AND o.expires_on >= ?
             ORDER BY o.id DESC LIMIT 1",
            [$leadId, date('Y-m-d')]
        )->fetch();

        return $row ?: null;
    }

    /** Every offer ever made to this tenant, newest first — their own history. */
    public static function forTenant(int $leadId): array
    {
        return Database::run(
            'SELECT o.*, r.name AS room_name, r.room_code, r.location
             FROM renewal_offers o
             LEFT JOIN rooms r ON r.id = o.room_id
             WHERE o.lead_id = ?
             ORDER BY o.id DESC',
            [$leadId]
        )->fetchAll();
    }

    /** Every offer this owner has made, newest first. */
    public static function forOwner(string $ownerName): array
    {
        return Database::run(
            'SELECT o.*, l.name AS tenant_name, l.wa_phone, r.name AS room_name, r.room_code
             FROM renewal_offers o
             JOIN leads l ON l.id = o.lead_id
             LEFT JOIN rooms r ON r.id = o.room_id
             WHERE o.owner_name = ?
             ORDER BY o.id DESC',
            [$ownerName]
        )->fetchAll();
    }

    /**
     * The newest offer on each of these tenancies, keyed by agreement_id — one
     * query for the whole tenancies page instead of one per card.
     *
     * @param int[] $agreementIds
     * @return array<int, array<string, mixed>>
     */
    public static function latestForAgreements(array $agreementIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $agreementIds)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $rows = Database::run(
            "SELECT o.*
             FROM renewal_offers o
             JOIN (
                 SELECT agreement_id, MAX(id) AS latest_id
                 FROM renewal_offers
                 WHERE agreement_id IN ($placeholders)
                 GROUP BY agreement_id
             ) newest ON newest.latest_id = o.id",
            $ids
        )->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['agreement_id']] = $row;
        }

        return $out;
    }
}
