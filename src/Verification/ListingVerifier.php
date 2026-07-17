<?php

declare(strict_types=1);

namespace App\Verification;

use App\AI\Memory\EpisodicLogger;
use App\Core\Database;
use App\Models\VerifiedListing;

/**
 * Verified-badge logic. Ownership and GPS checks are manual admin reviews;
 * scam screening is an AI-assisted signal for human review, not a verdict.
 */
final class ListingVerifier
{
    public static function recordOwnershipDoc(int $roomId, string $docPath): array
    {
        $row = VerifiedListing::ensure($roomId);
        if (!in_array($row['ownership_review_status'] ?? 'not_submitted', ['not_submitted', 'rejected'], true)) {
            throw new \RuntimeException('Ownership evidence can only be submitted initially or after rejection.');
        }
        self::updateEvidenceSubmission(
            (int) $row['id'],
            'ownership_evidence_version',
            'ownership_review_status',
            (int) ($row['ownership_evidence_version'] ?? 0),
            [
            'ownership_doc_path' => $docPath,
            'ownership_verified' => 0,
            'ownership_review_status' => 'pending',
            'ownership_review_note' => null,
            'ownership_reviewed_by' => null,
            'ownership_reviewed_at' => null,
            ]
        );

        return self::recomputeBadge($roomId);
    }

    /** Backwards-compatible wrapper for older callers. */
    public static function confirmOwnership(int $roomId, bool $confirmed): array
    {
        return self::reviewOwnership(
            $roomId,
            $confirmed ? 'approved' : 'rejected',
            'admin',
            $confirmed ? '' : 'Rejected by admin.'
        );
    }

    public static function reviewOwnership(
        int $roomId,
        string $decision,
        string $reviewer,
        string $note = '',
        ?int $expectedEvidenceVersion = null
    ): array {
        self::validateDecision($decision, $note);
        $row = VerifiedListing::ensure($roomId);
        if (empty($row['ownership_doc_path'])) {
            throw new \RuntimeException('No ownership document has been submitted.');
        }

        self::updateReviewDecision((int) $row['id'], 'ownership_evidence_version', $expectedEvidenceVersion, [
            'ownership_verified' => $decision === 'approved' ? 1 : 0,
            'ownership_review_status' => $decision,
            'ownership_review_note' => $note !== '' ? $note : null,
            'ownership_reviewed_by' => trim($reviewer) !== '' ? trim($reviewer) : 'admin',
            'ownership_reviewed_at' => date('Y-m-d H:i:s'),
        ]);
        EpisodicLogger::activity(
            'listing_ownership_review',
            null,
            null,
            null,
            self::reviewDetail($roomId, $decision, $reviewer, $note)
        );

        return self::recomputeBadge($roomId);
    }

    public static function recordGps(int $roomId, float $lat, float $lng, bool $adminConfirmedMatch): array
    {
        $row = VerifiedListing::ensure($roomId);
        if (!$adminConfirmedMatch
            && !in_array($row['gps_review_status'] ?? 'not_submitted', ['not_submitted', 'rejected'], true)) {
            throw new \RuntimeException('Location evidence can only be submitted initially or after rejection.');
        }
        $data = [
            'gps_lat' => $lat,
            'gps_lng' => $lng,
            'gps_matched' => $adminConfirmedMatch ? 1 : 0,
            'gps_review_status' => $adminConfirmedMatch ? 'approved' : 'pending',
            'gps_review_note' => null,
            'gps_reviewed_by' => $adminConfirmedMatch ? 'admin' : null,
            'gps_reviewed_at' => $adminConfirmedMatch ? date('Y-m-d H:i:s') : null,
        ];
        if ($adminConfirmedMatch) {
            $data['gps_evidence_version'] = (int) ($row['gps_evidence_version'] ?? 0) + 1;
            VerifiedListing::update((int) $row['id'], $data);
        } else {
            self::updateEvidenceSubmission(
                (int) $row['id'],
                'gps_evidence_version',
                'gps_review_status',
                (int) ($row['gps_evidence_version'] ?? 0),
                $data
            );
        }
        EpisodicLogger::activity(
            'listing_gps_review',
            null,
            null,
            null,
            "room #$roomId -> " . ($adminConfirmedMatch ? 'matched' : 'submitted')
        );

        return self::recomputeBadge($roomId);
    }

    public static function reviewGps(
        int $roomId,
        string $decision,
        string $reviewer,
        string $note = '',
        ?int $expectedEvidenceVersion = null
    ): array {
        self::validateDecision($decision, $note);
        $row = VerifiedListing::ensure($roomId);
        if ($row['gps_lat'] === null || $row['gps_lng'] === null) {
            throw new \RuntimeException('No location coordinates have been submitted.');
        }

        self::updateReviewDecision((int) $row['id'], 'gps_evidence_version', $expectedEvidenceVersion, [
            'gps_matched' => $decision === 'approved' ? 1 : 0,
            'gps_review_status' => $decision,
            'gps_review_note' => $note !== '' ? $note : null,
            'gps_reviewed_by' => trim($reviewer) !== '' ? trim($reviewer) : 'admin',
            'gps_reviewed_at' => date('Y-m-d H:i:s'),
        ]);
        EpisodicLogger::activity(
            'listing_gps_review',
            null,
            null,
            null,
            self::reviewDetail($roomId, $decision, $reviewer, $note)
        );

        return self::recomputeBadge($roomId);
    }

    public static function recomputeBadge(int $roomId): array
    {
        $row = VerifiedListing::ensure($roomId);

        $highSeverityScam = false;
        foreach (json_decode($row['scam_flags'] ?? '[]', true) ?: [] as $flag) {
            if (($flag['severity'] ?? '') === 'high') {
                $highSeverityScam = true;
            }
        }

        $badge = ($row['ownership_review_status'] ?? '') === 'approved'
            && ($row['gps_review_status'] ?? '') === 'approved'
            && !$highSeverityScam;

        VerifiedListing::update((int) $row['id'], [
            'verified_badge' => $badge ? 1 : 0,
            'verified_at' => $badge ? ($row['verified_at'] ?? date('Y-m-d H:i:s')) : null,
        ]);

        return VerifiedListing::forRoom($roomId);
    }

    /** The tenant-facing checklist for the Verified Listing Card. */
    public static function checklist(array $row): array
    {
        $scamFlags = json_decode($row['scam_flags'] ?? '[]', true) ?: [];

        return [
            ['Ownership document reviewed by BeLive admin', ($row['ownership_review_status'] ?? '') === 'approved'],
            ['Location coordinates match the listed address', ($row['gps_review_status'] ?? '') === 'approved'],
            ['No scam patterns flagged by AI screening', $row['scam_checked_at'] !== null && $scamFlags === []],
        ];
    }

    private static function validateDecision(string $decision, string $note): void
    {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException('Review decision must be approved or rejected.');
        }
        if ($decision === 'rejected' && trim($note) === '') {
            throw new \InvalidArgumentException('A rejection reason is required.');
        }
        if (strlen($note) > 500) {
            throw new \InvalidArgumentException('Review notes must be 500 characters or fewer.');
        }
    }

    private static function reviewDetail(int $roomId, string $decision, string $reviewer, string $note): string
    {
        $detail = "room #$roomId -> $decision by " . (trim($reviewer) !== '' ? trim($reviewer) : 'admin');
        return $note !== '' ? $detail . ': ' . $note : $detail;
    }

    /**
     * Apply a decision only if the evidence version still matches the page the
     * admin reviewed. This prevents approving an owner replacement submitted
     * between opening the review and pressing Approve.
     */
    private static function updateReviewDecision(
        int $id,
        string $versionColumn,
        ?int $expectedVersion,
        array $data
    ): void {
        if ($expectedVersion === null) {
            VerifiedListing::update($id, $data);
            return;
        }

        $sets = implode(', ', array_map(static fn (string $column): string => "`$column` = ?", array_keys($data)));
        $params = [...array_values($data), $id, $expectedVersion];
        $updated = Database::run(
            "UPDATE `verified_listings` SET $sets WHERE id = ? AND `$versionColumn` = ?",
            $params
        )->rowCount();

        if ($updated !== 1) {
            throw new \RuntimeException('This evidence changed after the review page was opened. Reload and review the latest submission.');
        }
    }

    /** Atomically accept one initial/replacement submission and advance its version. */
    private static function updateEvidenceSubmission(
        int $id,
        string $versionColumn,
        string $statusColumn,
        int $expectedVersion,
        array $data
    ): void {
        $sets = implode(', ', array_map(static fn (string $column): string => "`$column` = ?", array_keys($data)));
        $params = [...array_values($data), $id, $expectedVersion];
        $updated = Database::run(
            "UPDATE `verified_listings`
             SET $sets, `$versionColumn` = `$versionColumn` + 1
             WHERE id = ?
               AND `$versionColumn` = ?
               AND `$statusColumn` IN ('not_submitted', 'rejected')",
            $params
        )->rowCount();

        if ($updated !== 1) {
            throw new \RuntimeException('This evidence was already submitted or changed in another session. Reload before trying again.');
        }
    }
}
