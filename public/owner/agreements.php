<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Agreements\AgreementRenderer;
use App\Agreements\AgreementWorkflow;
use App\Agreements\OwnerParticulars;
use App\Core\Auth;
use App\Models\DigitalAgreement;

require dirname(__DIR__) . '/_portal_layout.php';
$owner = require_owner();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'sign') {
    Auth::requireCsrf();
    $agreementId = (int) ($_POST['agreement_id'] ?? 0);
    $version = filter_var($_POST['stage_version'] ?? null, FILTER_VALIDATE_INT);

    try {
        if ($version === false || $version === null) {
            throw new RuntimeException('This page is out of date — reload it and try again.');
        }
        AgreementWorkflow::submitOwnerParticulars($agreementId, $_POST, $owner, $version);
        set_flash('success', 'Signed and sent back to BeLive. They check it, then the tenant signs — you will see the status change here.');
    } catch (Throwable $e) {
        set_flash('danger', $e->getMessage());
    }

    header('Location: /owner/agreements');
    exit;
}

$agreements = DigitalAgreement::forOwner($owner);
$actionable = array_values(array_filter($agreements, static fn ($a) => $a['status'] === 'owner_review'));
$others = array_values(array_filter($agreements, static fn ($a) => $a['status'] !== 'owner_review'));

portal_header('owner', 'Agreements', 'agreements');
?>
<div class="portal-hero">
    <div class="tagline">Everything in writing — automatically.</div>
    <h1>Digital agreements</h1>
    <p>BeLive drafts the agreement the moment your tenant confirms the room. You add your details, sign,
    and it goes back for checking before the tenant ever sees it.</p>
</div>

<?php if ($actionable === []): ?>
    <div class="belive-card" style="margin-bottom:16px">
        <div class="belive-card-title">✅ Nothing waiting on you</div>
        <p class="belive-muted">When a tenant confirms one of your rooms, BeLive drafts their tenancy agreement
        and sends it here for your details and signature.</p>
    </div>
<?php endif; ?>

<?php foreach ($actionable as $agreement): ?>
    <?php $document = AgreementRenderer::render($agreement, true); ?>
    <div class="belive-card owner-agreement-card" style="margin-bottom:16px">
        <div class="belive-card-title">✍️ Needs your details and signature — BL-AGR-<?= sprintf('%05d', (int) $agreement['id']) ?></div>
        <p class="belive-muted" style="font-size:13.5px">
            <?= e($agreement['room_name'] ?? 'Your room') ?> · tenant <?= e($agreement['tenant_name'] ?: $agreement['wa_phone']) ?>
            <?php if ($agreement['starts_on'] !== null && $agreement['ends_on'] !== null): ?>
                · <?= e(date('j M Y', strtotime($agreement['starts_on']))) ?>
                to <?= e(date('j M Y', strtotime($agreement['ends_on']))) ?>
            <?php endif; ?>
            <?php if ($agreement['monthly_rent_rm'] !== null): ?>
                · RM <?= e(number_format((float) $agreement['monthly_rent_rm'], 2)) ?>/month
            <?php endif; ?>
        </p>

        <?php if ($agreement['admin_review_note'] !== null && $agreement['owner_signed_at'] === null && $agreement['admin_reviewed_at'] !== null): ?>
            <div class="belive-alert warning">
                <strong>BeLive sent this back for a correction:</strong>
                <div><?= e($agreement['admin_review_note']) ?></div>
            </div>
        <?php endif; ?>

        <details class="agreement-preview">
            <summary>Read the agreement before you sign</summary>
            <div class="agreement-text"><?= e($document) ?></div>
        </details>

        <form method="post" action="/owner/agreements" class="owner-agreement-form">
            <input type="hidden" name="do" value="sign">
            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
            <input type="hidden" name="agreement_id" value="<?= (int) $agreement['id'] ?>">
            <input type="hidden" name="stage_version" value="<?= (int) $agreement['stage_version'] ?>">

            <h3>Your details</h3>
            <p class="belive-muted" style="font-size:13px">These become the landlord's particulars in the agreement.
            Your NRIC and account number are encrypted before they are stored.</p>

            <div class="owner-agreement-grid">
                <div class="belive-field">
                    <label for="owner_full_name-<?= (int) $agreement['id'] ?>">Full name (as per NRIC/passport)</label>
                    <input id="owner_full_name-<?= (int) $agreement['id'] ?>" name="owner_full_name" type="text" required
                           value="<?= e($agreement['owner_full_name'] ?? $owner) ?>">
                </div>
                <div class="belive-field">
                    <label for="owner_ic-<?= (int) $agreement['id'] ?>">NRIC or passport number</label>
                    <input id="owner_ic-<?= (int) $agreement['id'] ?>" name="owner_ic" type="text" required
                           placeholder="880101-14-5501" autocomplete="off">
                </div>
                <div class="belive-field">
                    <label for="owner_email-<?= (int) $agreement['id'] ?>">Email</label>
                    <input id="owner_email-<?= (int) $agreement['id'] ?>" name="owner_email" type="email" required
                           value="<?= e($agreement['owner_email'] ?? '') ?>">
                </div>
                <div class="belive-field">
                    <label for="owner_phone-<?= (int) $agreement['id'] ?>">Phone</label>
                    <input id="owner_phone-<?= (int) $agreement['id'] ?>" name="owner_phone" type="tel" required
                           value="<?= e($agreement['owner_phone'] ?? '') ?>" placeholder="0123456789">
                </div>
            </div>
            <div class="belive-field">
                <label for="owner_address-<?= (int) $agreement['id'] ?>">Correspondence address</label>
                <input id="owner_address-<?= (int) $agreement['id'] ?>" name="owner_address" type="text" required
                       value="<?= e($agreement['owner_address'] ?? '') ?>" placeholder="Street, postcode, city, state">
            </div>

            <h3>Where rent is paid</h3>
            <div class="owner-agreement-grid">
                <div class="belive-field">
                    <label for="owner_bank_name-<?= (int) $agreement['id'] ?>">Bank</label>
                    <select id="owner_bank_name-<?= (int) $agreement['id'] ?>" name="owner_bank_name" required>
                        <option value="">Choose your bank</option>
                        <?php foreach (OwnerParticulars::BANKS as $bank): ?>
                            <option value="<?= e($bank) ?>" <?= ($agreement['owner_bank_name'] ?? '') === $bank ? 'selected' : '' ?>><?= e($bank) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="belive-field">
                    <label for="owner_bank_other-<?= (int) $agreement['id'] ?>">If "Other", name the bank</label>
                    <input id="owner_bank_other-<?= (int) $agreement['id'] ?>" name="owner_bank_other" type="text">
                </div>
                <div class="belive-field">
                    <label for="owner_bank_holder-<?= (int) $agreement['id'] ?>">Account holder name</label>
                    <input id="owner_bank_holder-<?= (int) $agreement['id'] ?>" name="owner_bank_holder" type="text" required
                           value="<?= e($agreement['owner_bank_holder'] ?? '') ?>">
                </div>
                <div class="belive-field">
                    <label for="owner_bank_account-<?= (int) $agreement['id'] ?>">Account number</label>
                    <input id="owner_bank_account-<?= (int) $agreement['id'] ?>" name="owner_bank_account" type="text" required
                           inputmode="numeric" autocomplete="off" placeholder="Digits only">
                </div>
            </div>

            <div class="belive-field">
                <label for="owner_extra_terms-<?= (int) $agreement['id'] ?>">Anything else to add? <span class="belive-muted">(optional)</span></label>
                <textarea id="owner_extra_terms-<?= (int) $agreement['id'] ?>" name="owner_extra_terms" rows="3" maxlength="1500"
                          placeholder="House rules or conditions specific to your property"><?= e($agreement['owner_extra_terms'] ?? '') ?></textarea>
                <div class="hint">Added to the agreement as your own terms — BeLive reviews it before the tenant sees it.</div>
            </div>

            <h3>Sign</h3>
            <div class="belive-field">
                <label for="owner_signature-<?= (int) $agreement['id'] ?>">Type your full name to sign</label>
                <input id="owner_signature-<?= (int) $agreement['id'] ?>" name="owner_signature" type="text" required
                       placeholder="Exactly as entered above">
                <div class="hint">Your typed name and the time you signed are recorded against this document.</div>
            </div>
            <button type="submit" class="belive-btn-primary">Sign and send back to BeLive</button>
        </form>
    </div>
<?php endforeach; ?>

<div class="belive-card">
    <div class="belive-card-title">📄 Your agreements</div>
    <?php if ($others === []): ?>
        <p class="belive-muted">Nothing else yet.</p>
    <?php else: ?>
        <div class="belive-table-wrap">
            <table class="belive-table">
                <thead><tr><th>#</th><th>Tenant</th><th>Room</th><th>Term</th><th>Stage</th><th>Your signature</th></tr></thead>
                <tbody>
                <?php foreach ($others as $agreement): ?>
                    <?php $stage = DigitalAgreement::stage($agreement['status']); ?>
                    <tr>
                        <td>BL-AGR-<?= sprintf('%05d', (int) $agreement['id']) ?></td>
                        <td><?= e($agreement['tenant_name'] ?: $agreement['wa_phone']) ?></td>
                        <td style="font-size:13px"><?= e($agreement['room_name'] ?? '—') ?></td>
                        <td style="font-size:13px; white-space:nowrap">
                            <?php if ($agreement['starts_on'] !== null && $agreement['ends_on'] !== null): ?>
                                <?= e(date('j M Y', strtotime($agreement['starts_on']))) ?><br>
                                <span class="belive-muted">to <?= e(date('j M Y', strtotime($agreement['ends_on']))) ?></span>
                            <?php else: ?>
                                <span class="belive-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="belive-badge <?= $agreement['status'] === 'completed' ? '' : ($agreement['status'] === 'cancelled' ? 'danger' : 'muted') ?>"><?= e($stage['label']) ?></span></td>
                        <td style="font-size:13px">
                            <?= $agreement['owner_signed_at'] !== null
                                ? e($agreement['owner_signed_name'] . ' · ' . $agreement['owner_signed_at'])
                                : '<span class="belive-muted">—</span>' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="belive-muted" style="font-size:12px; margin-top:10px">
            Signing here means typing your own full name against a dated record — it is not a cryptographic
            e-signature, and the agreement still needs stamping under the Stamp Act 1949.
        </p>
    <?php endif; ?>
</div>
<?php portal_footer();
