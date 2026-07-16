<?php

declare(strict_types=1);

namespace App\Verification;

use App\AI\Memory\EpisodicLogger;
use App\Models\VerifiedListing;

/**
 * Verified-badge logic. Two checks, both HONEST simplifications stated openly
 * in the demo script:
 *  - ownership: admin reviews an uploaded ownership document and confirms it
 *    manually (a review flag, NOT a real eKYC API)
 *  - GPS: admin eyeballs the owner-provided coordinates against the listed
 *    address on a map link and confirms the match manually
 * Badge = ownership confirmed AND GPS confirmed AND no high-severity scam flag.
 */
final class ListingVerifier
{
    public static function recordOwnershipDoc(int $roomId, string $docPath): array
    {
        $row = VerifiedListing::ensure($roomId);
        VerifiedListing::update((int) $row['id'], [
            'ownership_doc_path' => $docPath,
            'ownership_verified' => 0, // needs fresh admin review
        ]);

        return self::recomputeBadge($roomId);
    }

    public static function confirmOwnership(int $roomId, bool $confirmed): array
    {
        $row = VerifiedListing::ensure($roomId);
        VerifiedListing::update((int) $row['id'], ['ownership_verified' => $confirmed ? 1 : 0]);
        EpisodicLogger::activity('listing_ownership_review', null, null, null, "room #$roomId → " . ($confirmed ? 'confirmed' : 'rejected'));

        return self::recomputeBadge($roomId);
    }

    public static function recordGps(int $roomId, float $lat, float $lng, bool $adminConfirmedMatch): array
    {
        $row = VerifiedListing::ensure($roomId);
        VerifiedListing::update((int) $row['id'], [
            'gps_lat'     => $lat,
            'gps_lng'     => $lng,
            'gps_matched' => $adminConfirmedMatch ? 1 : 0,
        ]);
        EpisodicLogger::activity('listing_gps_review', null, null, null, "room #$roomId → " . ($adminConfirmedMatch ? 'matched' : 'not matched'));

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

        $badge = (int) $row['ownership_verified'] === 1
            && (int) $row['gps_matched'] === 1
            && !$highSeverityScam;

        VerifiedListing::update((int) $row['id'], [
            'verified_badge' => $badge ? 1 : 0,
            'verified_at'    => $badge ? ($row['verified_at'] ?? date('Y-m-d H:i:s')) : null,
        ]);

        return VerifiedListing::forRoom($roomId);
    }

    /** The tenant-facing checklist for the Verified Listing Card. */
    public static function checklist(array $row): array
    {
        $scamFlags = json_decode($row['scam_flags'] ?? '[]', true) ?: [];

        return [
            ['Ownership document reviewed by BeLive admin', (int) $row['ownership_verified'] === 1],
            ['Location coordinates match the listed address', (int) $row['gps_matched'] === 1],
            ['No scam patterns flagged by AI screening', $row['scam_checked_at'] !== null && $scamFlags === []],
        ];
    }
}
