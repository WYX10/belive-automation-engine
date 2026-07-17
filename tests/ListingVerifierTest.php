<?php

declare(strict_types=1);

/**
 * Proves the owner submission -> admin decision -> badge workflow, including
 * rejection feedback and resubmission state resets.
 */

use App\Models\Room;
use App\Verification\ListingVerifier;

$roomId = Room::create([
    'name' => 'Verification Test Room',
    'location' => 'Cheras',
    'room_type' => 'master',
    'owner_name' => 'Verification Test Owner',
    'address' => '1 Test Street, Cheras',
]);

$submitted = ListingVerifier::recordOwnershipDoc($roomId, 'https://example.com/ownership.pdf');
check('ownership submission enters the admin queue', $submitted['ownership_review_status'] === 'pending');
check('fresh ownership submission is not pre-approved', (int) $submitted['ownership_verified'] === 0);

$ownershipApproved = ListingVerifier::reviewOwnership(
    $roomId,
    'approved',
    'test-admin',
    'Name and address match.',
    (int) $submitted['ownership_evidence_version']
);
check('admin can approve ownership evidence', $ownershipApproved['ownership_review_status'] === 'approved');
check('ownership review records reviewer and note', $ownershipApproved['ownership_reviewed_by'] === 'test-admin'
    && $ownershipApproved['ownership_review_note'] === 'Name and address match.');

$gpsSubmitted = ListingVerifier::recordGps($roomId, 3.2010, 101.7180, false);
check('GPS submission enters the admin queue', $gpsSubmitted['gps_review_status'] === 'pending');

$unreviewedReplacementBlocked = false;
try {
    ListingVerifier::recordGps($roomId, 3.2020, 101.7190, false);
} catch (RuntimeException) {
    $unreviewedReplacementBlocked = true;
}
check('pending evidence cannot be replaced before an admin decision', $unreviewedReplacementBlocked);

$verified = ListingVerifier::reviewGps(
    $roomId,
    'approved',
    'test-admin',
    'Pin matches the listed address.',
    (int) $gpsSubmitted['gps_evidence_version']
);
check('admin can approve the location match', $verified['gps_review_status'] === 'approved');
check('approved ownership and location activate the badge', (int) $verified['verified_badge'] === 1);

$missingReasonRejected = false;
try {
    ListingVerifier::reviewGps($roomId, 'rejected', 'test-admin');
} catch (InvalidArgumentException) {
    $missingReasonRejected = true;
}
check('rejection requires an owner-facing reason', $missingReasonRejected);

$rejected = ListingVerifier::reviewGps(
    $roomId,
    'rejected',
    'test-admin',
    'Pin is on the wrong building.',
    (int) $verified['gps_evidence_version']
);
check('rejection removes the verified badge', $rejected['gps_review_status'] === 'rejected'
    && (int) $rejected['verified_badge'] === 0);
check('rejection feedback is stored for the owner', $rejected['gps_review_note'] === 'Pin is on the wrong building.');

$resubmitted = ListingVerifier::recordGps($roomId, 3.2020, 101.7190, false);
check('owner resubmission returns GPS to pending', $resubmitted['gps_review_status'] === 'pending');
check('accepted resubmission advances the evidence version exactly once',
    (int) $resubmitted['gps_evidence_version'] === (int) $rejected['gps_evidence_version'] + 1);
check('resubmission clears the previous rejection audit fields', $resubmitted['gps_review_note'] === null
    && $resubmitted['gps_reviewed_by'] === null
    && $resubmitted['gps_reviewed_at'] === null);

$staleDecisionRejected = false;
try {
    ListingVerifier::reviewGps(
        $roomId,
        'approved',
        'test-admin',
        'Reviewed the earlier coordinates.',
        (int) $gpsSubmitted['gps_evidence_version']
    );
} catch (RuntimeException) {
    $staleDecisionRejected = true;
}
check('an admin decision for stale evidence is rejected atomically', $staleDecisionRejected);
