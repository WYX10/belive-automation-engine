<?php

declare(strict_types=1);

/**
 * Proves the agreement round trip: admin drafts, the owner completes their
 * particulars and signs, admin checks it, the tenant signs — and that no stage
 * can be skipped, no stale form can overwrite a newer state, and the tenant
 * never sees a document that is still being prepared.
 */

use App\Agreements\AgreementRenderer;
use App\Agreements\AgreementWorkflow;
use App\Agreements\DigitalAgreementGenerator;
use App\Agreements\OwnerParticulars;
use App\Core\Database;
use App\Models\AgreementEvent;
use App\Models\DigitalAgreement;
use App\Models\Lead;
use App\Models\Room;

// Deterministic key for the throwaway test database — the encrypted-at-rest
// columns must round-trip regardless of what the developer's .env holds.
$_ENV['APP_ENCRYPTION_KEY'] = base64_encode(str_repeat('belive-test-key!', 2));

$leadId = Lead::create([
    'wa_phone' => '601199999801',
    'name' => 'Workflow Tenant',
    'source_channel' => 'whatsapp',
    'preferred_tenure' => '12_month',
    'move_in_date' => '2026-09-01',
]);
$roomId = Room::create([
    'name' => 'Workflow Test Room',
    'location' => 'Cheras',
    'room_type' => 'master',
    'owner_name' => 'Workflow Owner',
    'address' => '9 Workflow Road, Cheras',
    'status' => 'available',
]);
Database::run(
    'INSERT INTO room_pricing (room_id, tenure, price, is_best_value) VALUES (?, ?, ?, ?)',
    [$roomId, '12_month', 900, 1]
);
Database::run(
    "INSERT INTO bookings (lead_id, room_id, viewing_datetime, status) VALUES (?, ?, '2026-08-20 15:00:00', 'confirmed')",
    [$leadId, $roomId]
);

// ---- the admin start queue -------------------------------------------------
$queue = DigitalAgreement::awaitingGeneration();
$queued = array_values(array_filter($queue, fn ($row) => (int) $row['lead_id'] === $leadId));
check('a confirmed rental appears in the admin start queue', count($queued) === 1
    && (int) $queued[0]['room_id'] === $roomId);

// ---- draft -----------------------------------------------------------------
$agreement = DigitalAgreementGenerator::generate($leadId, $roomId, '2026-09-01', 'admin');
$id = (int) $agreement['id'];

check('a new agreement starts as an admin-held draft', $agreement['status'] === 'draft');
check('the rent is frozen onto the agreement at generation', (float) $agreement['monthly_rent_rm'] === 900.0);
check('the agreement is routed to the room owner', $agreement['owner_name'] === 'Workflow Owner');
check('the draft leaves the landlord details as placeholders',
    str_contains($agreement['agreement_text'], '{{LANDLORD_NAME}}')
    && str_contains($agreement['agreement_text'], '{{BANK_ACCOUNT_NO}}'));

$drafted = DigitalAgreement::withContext($id);
check('an unfilled draft renders the landlord fields as pending, never blank',
    str_contains(AgreementRenderer::render($drafted), 'to be completed by the owner'));

// A tenant must not see a document that is still being prepared.
check('the tenant cannot see a draft', DigitalAgreement::visibleToTenant($leadId) === null);

// ---- stage guards ----------------------------------------------------------
$version = (int) $drafted['stage_version'];
$rejected = false;
try {
    AgreementWorkflow::approveForTenant($id, 'admin', '', $version);
} catch (Throwable $e) {
    $rejected = true;
}
check('admin cannot release a draft to the tenant without the owner', $rejected);

$rejected = false;
try {
    AgreementWorkflow::submitOwnerParticulars($id, [], 'Workflow Owner', $version);
} catch (Throwable $e) {
    $rejected = true;
}
check('the owner cannot sign an agreement that was never sent to them', $rejected);

// ---- admin sends it to the owner -------------------------------------------
$sent = AgreementWorkflow::sendToOwner($id, 'admin', $version);
check('sending to the owner moves it to owner_review', $sent['status'] === 'owner_review');
check('each hand-off bumps the stage version', (int) $sent['stage_version'] === $version + 1);
check('the tenant still cannot see it while the owner has it', DigitalAgreement::visibleToTenant($leadId) === null);

$stale = false;
try {
    AgreementWorkflow::sendToOwner($id, 'admin', $version); // the version it was rendered from
} catch (Throwable $e) {
    $stale = true;
}
check('a stale form cannot re-run a hand-off', $stale);

// ---- the owner completes their particulars and signs -----------------------
$particulars = [
    'owner_full_name'     => 'Tan Sri Workflow Owner',
    'owner_ic'            => '880101-14-5501',
    'owner_email'         => 'owner@example.com',
    'owner_phone'         => '0123456789',
    'owner_address'       => '12 Jalan Owner, 43000 Kajang, Selangor',
    'owner_bank_name'     => 'Maybank',
    'owner_bank_holder'   => 'Tan Sri Workflow Owner',
    'owner_bank_account'  => '512345678901',
    'owner_extra_terms'   => 'No pets in the unit.',
    'owner_signature'     => 'Tan Sri Workflow Owner',
];

$wrongOwner = false;
try {
    AgreementWorkflow::submitOwnerParticulars($id, $particulars, 'Someone Else', (int) $sent['stage_version']);
} catch (Throwable $e) {
    $wrongOwner = true;
}
check('another owner account cannot sign this agreement', $wrongOwner);

$badSignature = false;
try {
    AgreementWorkflow::submitOwnerParticulars(
        $id,
        ['owner_signature' => 'Someone Else'] + $particulars,
        'Workflow Owner',
        (int) $sent['stage_version']
    );
} catch (Throwable $e) {
    $badSignature = true;
}
check('a signature that is not the declared name is rejected', $badSignature);

$signed = AgreementWorkflow::submitOwnerParticulars($id, $particulars, 'Workflow Owner', (int) $sent['stage_version']);
check('the signed agreement returns to admin for checking', $signed['status'] === 'admin_review');
check('the owner signature is recorded with a timestamp',
    $signed['owner_signed_name'] === 'Tan Sri Workflow Owner' && $signed['owner_signed_at'] !== null);

// ---- encryption at rest ----------------------------------------------------
$stored = Database::run('SELECT * FROM digital_agreements WHERE id = ?', [$id])->fetch();
check('the NRIC is not stored in the clear',
    !str_contains((string) $stored['owner_ic_enc'], '880101') && $stored['owner_ic_last4'] === '5501');
check('the bank account number is not stored in the clear',
    !str_contains((string) $stored['owner_bank_account_enc'], '512345678901')
    && $stored['owner_bank_account_last4'] === '8901');

$revealed = OwnerParticulars::reveal($signed);
check('the encrypted fields decrypt back for the document',
    $revealed['ic'] === '880101-14-5501' && $revealed['account_no'] === '512345678901');
check('masked views never expose the full account number',
    OwnerParticulars::masked($signed)['account_no'] === '•••• 8901');

$document = AgreementRenderer::render($signed);
check('the signed document carries the landlord details, not placeholders',
    str_contains($document, 'Tan Sri Workflow Owner')
    && str_contains($document, '512345678901')
    && !str_contains($document, '{{LANDLORD_NAME}}')
    && !str_contains($document, 'to be completed by the owner'));
check('the owner\'s additional terms are part of the document',
    str_contains($document, 'No pets in the unit.'));
check('the schedule carries the frozen rent and the term dates',
    str_contains($document, '900.00') && str_contains($document, '1 September 2026'));
check('a masked render hides the account number from list views',
    str_contains(AgreementRenderer::render($signed, false), '•••• 8901'));

// The tenant may not sign until admin has released it.
check('the tenant cannot see it while it is back with admin', DigitalAgreement::visibleToTenant($leadId) === null);

// ---- admin sends it back, which voids the signature ------------------------
$returned = AgreementWorkflow::returnToOwner($id, 'admin', 'Bank account is the wrong one.', (int) $signed['stage_version']);
check('a returned agreement goes back to the owner', $returned['status'] === 'owner_review');
check('sending it back voids the owner signature',
    $returned['owner_signed_at'] === null && $returned['owner_signed_name'] === null);
check('the owner sees what to correct', $returned['admin_review_note'] === 'Bank account is the wrong one.');

$noNote = false;
try {
    AgreementWorkflow::returnToOwner($id, 'admin', '   ', (int) $returned['stage_version']);
} catch (Throwable $e) {
    $noNote = true;
}
check('sending it back requires a reason the owner can act on', $noNote);

$resigned = AgreementWorkflow::submitOwnerParticulars($id, $particulars, 'Workflow Owner', (int) $returned['stage_version']);
check('the owner can correct and sign again', $resigned['status'] === 'admin_review' && $resigned['owner_signed_at'] !== null);

// ---- admin approves and the tenant signs -----------------------------------
$approved = AgreementWorkflow::approveForTenant($id, 'admin', 'Checked the bank details.', (int) $resigned['stage_version']);
check('approval releases it to the tenant', $approved['status'] === 'tenant_review');
check('the admin review is attributed', $approved['admin_reviewed_by'] === 'admin' && $approved['admin_reviewed_at'] !== null);

$visible = DigitalAgreement::visibleToTenant($leadId);
check('the tenant can now see exactly this agreement', $visible !== null && (int) $visible['id'] === $id);

$unticked = false;
try {
    AgreementWorkflow::tenantSign($id, 'Workflow Tenant', false, (int) $approved['stage_version']);
} catch (Throwable $e) {
    $unticked = true;
}
check('signing needs the confirmation box ticked', $unticked);

// The tenant can push back instead of signing.
$queried = AgreementWorkflow::tenantRequestChange($id, 'The end date is a month early.', (int) $approved['stage_version']);
check('a tenant change request goes back to admin', $queried['status'] === 'admin_review'
    && $queried['tenant_note'] === 'The end date is a month early.');
check('the tenant cannot sign while their query is open', DigitalAgreement::visibleToTenant($leadId) === null);

$rereleased = AgreementWorkflow::approveForTenant($id, 'admin', 'Dates confirmed correct.', (int) $queried['stage_version']);
$completed = AgreementWorkflow::tenantSign($id, 'Workflow Tenant', true, (int) $rereleased['stage_version']);
check('the tenant signature completes the agreement', $completed['status'] === 'completed');
check('the tenant signature is recorded with a timestamp',
    $completed['acknowledged_name'] === 'Workflow Tenant' && $completed['acknowledged_at'] !== null);
check('the completed document shows both signatures',
    str_contains(AgreementRenderer::render($completed), 'Signed by the Landlord : Tan Sri Workflow Owner')
    && str_contains(AgreementRenderer::render($completed), 'Signed by the Tenant   : Workflow Tenant'));

$signedTwice = false;
try {
    AgreementWorkflow::tenantSign($id, 'Workflow Tenant', true, (int) $completed['stage_version']);
} catch (Throwable $e) {
    $signedTwice = true;
}
check('a completed agreement cannot be signed again', $signedTwice);

// ---- the trail -------------------------------------------------------------
$trail = array_column(AgreementEvent::forAgreement($id), 'action');
check('every hand-off is on the record', $trail === [
    'generated', 'sent_to_owner', 'owner_signed', 'returned_to_owner', 'owner_signed',
    'admin_approved', 'tenant_queried', 'admin_approved', 'tenant_signed',
], implode(', ', $trail));

check('a signed room drops out of the start queue',
    array_filter(DigitalAgreement::awaitingGeneration(), fn ($row) => (int) $row['lead_id'] === $leadId) === []);
