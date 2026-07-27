<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * agreement_events — the hand-off trail for one agreement. Every stage change
 * writes a row naming who did it, so "the owner has had this for four days" or
 * "admin sent it back twice" is read from the record, not inferred.
 */
final class AgreementEvent extends BaseModel
{
    protected const TABLE = 'agreement_events';

    public const ROLES = ['admin', 'owner', 'tenant', 'system'];

    public static function record(
        int $agreementId,
        string $actorRole,
        ?string $actorName,
        string $action,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $note = null
    ): int {
        return self::create([
            'agreement_id' => $agreementId,
            'actor_role'   => in_array($actorRole, self::ROLES, true) ? $actorRole : 'system',
            'actor_name'   => $actorName !== null && $actorName !== '' ? mb_substr($actorName, 0, 120) : null,
            'action'       => mb_substr($action, 0, 40),
            'from_status'  => $fromStatus,
            'to_status'    => $toStatus,
            'note'         => $note !== null && $note !== '' ? mb_substr($note, 0, 500) : null,
        ]);
    }

    /** Oldest first — the trail reads as a story, not a stack. */
    public static function forAgreement(int $agreementId): array
    {
        return Database::run(
            'SELECT * FROM agreement_events WHERE agreement_id = ? ORDER BY id ASC',
            [$agreementId]
        )->fetchAll();
    }

    /** Human sentence for one trail row. */
    public static function describe(array $event): string
    {
        $who = $event['actor_name'] ?: ucfirst((string) $event['actor_role']);

        return match ($event['action']) {
            'generated'      => "$who generated the AI draft",
            'draft_edited'   => "$who edited the draft",
            'regenerated'    => "$who regenerated the draft",
            'sent_to_owner'  => "$who sent it to the owner for their details and signature",
            'owner_signed'   => "$who completed their particulars and signed",
            'admin_approved' => "$who approved it and released it to the tenant",
            'returned_to_owner' => "$who sent it back to the owner for correction",
            'tenant_signed'  => "$who signed the agreement",
            'tenant_queried' => "$who asked for a change before signing",
            'cancelled'      => "$who cancelled the agreement",
            default          => "$who — " . str_replace('_', ' ', (string) $event['action']),
        };
    }
}
