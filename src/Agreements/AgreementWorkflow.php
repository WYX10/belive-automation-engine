<?php

declare(strict_types=1);

namespace App\Agreements;

use App\AI\Memory\EpisodicLogger;
use App\Models\AgreementEvent;
use App\Models\DigitalAgreement;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

/**
 * The agreement's round trip, as one state machine.
 *
 *   draft ──send to owner──▶ owner_review ──owner signs──▶ admin_review
 *                                  ▲                            │
 *                                  └──── admin sends back ──────┤
 *                                                               ▼
 *                                       tenant_review ──tenant signs──▶ completed
 *                                             │
 *                                             └── tenant asks for a change ──▶ admin_review
 *
 * Two rules make a signature here mean something, and every method enforces
 * both:
 *
 *  1. A stage change is only legal from the stage before it. No screen can
 *     "skip" the owner or release an unsigned document to the tenant.
 *  2. Every form carries the stage_version it was rendered from. If the
 *     document moved on in another tab, the stale submission is rejected
 *     instead of overwriting the newer state.
 *
 * Sending the document back to the owner clears their signature: they signed a
 * document that is about to change, so that signature is void.
 */
final class AgreementWorkflow
{
    /** Admin releases the draft to the landlord for particulars + signature. */
    public static function sendToOwner(int $agreementId, string $adminName, int $expectedVersion): array
    {
        $agreement = self::lockedAt($agreementId, ['draft'], $expectedVersion);

        if (trim((string) $agreement['owner_name']) === '') {
            throw new DomainException('This agreement has no owner attached — the room it was generated from has no registered owner.');
        }

        return self::transition($agreement, 'owner_review', [
            'sent_to_owner_at' => date('Y-m-d H:i:s'),
        ], 'admin', $adminName, 'sent_to_owner', null);
    }

    /** Admin edits the AI draft before it ever leaves. Draft stage only. */
    public static function editDraft(int $agreementId, string $text, string $adminName, int $expectedVersion): array
    {
        return self::applyDraftText($agreementId, $text, $adminName, $expectedVersion, 'draft_edited');
    }

    /**
     * Write new body text over a draft — a hand edit, or a fresh model pass
     * (DigitalAgreementGenerator::regenerate, which supplies the new model name
     * in $extraColumns). The draft-stage and version guards live here so both
     * paths get them.
     *
     * @param array<string, mixed> $extraColumns
     */
    public static function applyDraftText(
        int $agreementId,
        string $text,
        string $adminName,
        int $expectedVersion,
        string $action,
        array $extraColumns = []
    ): array {
        $agreement = self::lockedAt($agreementId, ['draft'], $expectedVersion);

        $text = trim($text);
        if (mb_strlen($text) < 200) {
            throw new InvalidArgumentException('An agreement body this short is almost certainly a mistake — keep the full document text.');
        }

        return self::transition(
            $agreement,
            'draft',
            $extraColumns + ['agreement_text' => $text],
            'admin',
            $adminName,
            $action,
            null
        );
    }

    /**
     * The landlord completes their particulars and signs. This is the only
     * write path for those columns, and it only opens at owner_review.
     *
     * @param array<string, string> $input raw form values
     */
    public static function submitOwnerParticulars(int $agreementId, array $input, string $ownerName, int $expectedVersion): array
    {
        $agreement = self::lockedAt($agreementId, ['owner_review'], $expectedVersion);

        if ($agreement['owner_name'] !== $ownerName) {
            throw new DomainException('This agreement belongs to another owner account.');
        }

        $columns = OwnerParticulars::fromInput($input);

        return self::transition($agreement, 'admin_review', $columns, 'owner', $columns['owner_full_name'], 'owner_signed', null);
    }

    /** Admin checks what the landlord returned and releases it to the tenant. */
    public static function approveForTenant(int $agreementId, string $adminName, string $note, int $expectedVersion): array
    {
        $agreement = self::lockedAt($agreementId, ['admin_review'], $expectedVersion);

        if (!OwnerParticulars::complete($agreement)) {
            throw new DomainException('The landlord has not completed and signed their particulars yet.');
        }

        return self::transition($agreement, 'tenant_review', [
            'admin_review_note' => self::note($note),
            'admin_reviewed_by' => $adminName,
            'admin_reviewed_at' => date('Y-m-d H:i:s'),
            'sent_to_tenant_at' => date('Y-m-d H:i:s'),
            'tenant_note'       => null,
        ], 'admin', $adminName, 'admin_approved', self::note($note));
    }

    /**
     * Admin sends it back for correction. The landlord's signature is cleared —
     * they are being asked to change the document they signed.
     */
    public static function returnToOwner(int $agreementId, string $adminName, string $note, int $expectedVersion): array
    {
        $agreement = self::lockedAt($agreementId, ['admin_review'], $expectedVersion);

        $note = self::note($note);
        if ($note === null) {
            throw new InvalidArgumentException('Tell the owner what to correct — the note is what they act on.');
        }

        return self::transition($agreement, 'owner_review', [
            'owner_signed_name' => null,
            'owner_signed_at'   => null,
            'admin_review_note' => $note,
            'admin_reviewed_by' => $adminName,
            'admin_reviewed_at' => date('Y-m-d H:i:s'),
            'sent_to_owner_at'  => date('Y-m-d H:i:s'),
        ], 'admin', $adminName, 'returned_to_owner', $note);
    }

    /** The tenant signs. Both parties are now on the record. */
    public static function tenantSign(int $agreementId, string $typedName, bool $confirmed, int $expectedVersion): array
    {
        $agreement = self::lockedAt($agreementId, ['tenant_review'], $expectedVersion);

        $typedName = trim(preg_replace('/\s+/', ' ', $typedName));
        if (mb_strlen($typedName) < 3) {
            throw new InvalidArgumentException('Type your full name as your signature.');
        }
        if (!$confirmed) {
            throw new InvalidArgumentException('Tick the confirmation box to sign.');
        }
        if (!OwnerParticulars::complete($agreement)) {
            throw new DomainException('This agreement is missing the landlord signature and cannot be signed.');
        }

        return self::transition($agreement, 'completed', [
            'acknowledged_name' => $typedName,
            'acknowledged_at'   => date('Y-m-d H:i:s'),
        ], 'tenant', $typedName, 'tenant_signed', null);
    }

    /** The tenant reads it and wants something changed before signing. */
    public static function tenantRequestChange(int $agreementId, string $note, int $expectedVersion): array
    {
        $agreement = self::lockedAt($agreementId, ['tenant_review'], $expectedVersion);

        $note = self::note($note);
        if ($note === null) {
            throw new InvalidArgumentException('Tell us what needs changing so admin can act on it.');
        }

        return self::transition($agreement, 'admin_review', [
            'tenant_note'       => $note,
            'sent_to_tenant_at' => null,
        ], 'tenant', $agreement['tenant_name'] ?? null, 'tenant_queried', $note);
    }

    /** Void an agreement that should never be signed (wrong room, withdrawn). */
    public static function cancel(int $agreementId, string $adminName, string $note, int $expectedVersion): array
    {
        $agreement = self::lockedAt($agreementId, ['draft', 'owner_review', 'admin_review', 'tenant_review'], $expectedVersion);

        $note = self::note($note);
        if ($note === null) {
            throw new InvalidArgumentException('Record why this agreement is being cancelled.');
        }

        return self::transition($agreement, 'cancelled', [
            'admin_reviewed_by' => $adminName,
            'admin_reviewed_at' => date('Y-m-d H:i:s'),
            'admin_review_note' => $note,
        ], 'admin', $adminName, 'cancelled', $note);
    }

    /**
     * Load the agreement and refuse to touch it unless it is at an expected
     * stage AND the caller's form was rendered from the current version.
     *
     * @param string[] $allowedStages
     */
    private static function lockedAt(int $agreementId, array $allowedStages, int $expectedVersion): array
    {
        $agreement = DigitalAgreement::withContext($agreementId);
        if ($agreement === null) {
            throw new RuntimeException('Agreement not found.');
        }

        if (!in_array($agreement['status'], $allowedStages, true)) {
            throw new DomainException(sprintf(
                'This agreement is at "%s" and cannot take that action.',
                DigitalAgreement::STAGE_LABELS[$agreement['status']] ?? $agreement['status']
            ));
        }

        if ((int) $agreement['stage_version'] !== $expectedVersion) {
            throw new DomainException('Someone else moved this agreement on while you had it open. Reload the page and check the current state before acting.');
        }

        return $agreement;
    }

    /**
     * Apply a stage change: write the columns, bump the version, record the
     * hand-off, and mirror it into the app-wide activity log.
     *
     * @param array<string, mixed> $columns
     */
    private static function transition(
        array $agreement,
        string $toStatus,
        array $columns,
        string $actorRole,
        ?string $actorName,
        string $action,
        ?string $note
    ): array {
        $id = (int) $agreement['id'];
        $from = (string) $agreement['status'];

        DigitalAgreement::update($id, $columns + [
            'status'        => $toStatus,
            'stage_version' => (int) $agreement['stage_version'] + 1,
        ]);

        AgreementEvent::record($id, $actorRole, $actorName, $action, $from, $toStatus, $note);
        EpisodicLogger::activity(
            'agreement_' . $action,
            null,
            null,
            (int) $agreement['lead_id'],
            "agreement #$id $from → $toStatus" . ($actorName !== null ? " by $actorName" : '')
        );

        return DigitalAgreement::withContext($id) ?? $agreement;
    }

    private static function note(string $note): ?string
    {
        $note = trim($note);

        return $note === '' ? null : mb_substr($note, 0, 500);
    }
}
