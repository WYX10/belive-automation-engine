<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Agreements\AgreementRenderer;
use App\Agreements\AgreementWorkflow;
use App\Agreements\DigitalAgreementGenerator;
use App\Agreements\OwnerParticulars;
use App\Core\Auth;
use App\Models\AgreementEvent;
use App\Models\DigitalAgreement;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$adminName = (string) ($_SESSION['admin_username'] ?? 'admin');

$agreement = DigitalAgreement::withContext($id);
if ($agreement === null) {
    set_flash('danger', 'Agreement not found.');
    header('Location: /admin/agreements');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    $action = $_POST['action'] ?? '';
    $note = trim($_POST['note'] ?? '');
    $version = filter_var($_POST['stage_version'] ?? null, FILTER_VALIDATE_INT);

    try {
        if ($version === false || $version === null) {
            throw new RuntimeException('The form is stale — reload the page and try again.');
        }
        match ($action) {
            'edit_draft'   => AgreementWorkflow::editDraft($id, $_POST['agreement_text'] ?? '', $adminName, $version),
            'regenerate'   => DigitalAgreementGenerator::regenerate($id, $adminName, $version),
            'send_owner'   => AgreementWorkflow::sendToOwner($id, $adminName, $version),
            'approve'      => AgreementWorkflow::approveForTenant($id, $adminName, $note, $version),
            'return_owner' => AgreementWorkflow::returnToOwner($id, $adminName, $note, $version),
            'cancel'       => AgreementWorkflow::cancel($id, $adminName, $note, $version),
            default        => throw new RuntimeException('Unknown action.'),
        };
        set_flash('success', match ($action) {
            'edit_draft'   => 'Draft saved.',
            'regenerate'   => 'Eve rewrote the draft.',
            'send_owner'   => 'Sent to the owner — they now complete their details and sign.',
            'approve'      => 'Approved and released to the tenant for signing.',
            'return_owner' => 'Sent back to the owner with your note. Their signature has been cleared.',
            'cancel'       => 'Agreement cancelled.',
            default        => 'Done.',
        });
    } catch (Throwable $e) {
        set_flash('danger', $e->getMessage());
    }

    header('Location: /admin/agreements/view?id=' . $id);
    exit;
}

$stage = DigitalAgreement::stage($agreement['status']);
$version = (int) $agreement['stage_version'];
$events = AgreementEvent::forAgreement($id);
$particulars = OwnerParticulars::masked($agreement);
$ownerSigned = OwnerParticulars::complete($agreement);

// The landlord's NRIC and account number are only unmasked once admin is the
// one who has to check them — from the final review onwards.
$reveal = in_array($agreement['status'], ['admin_review', 'tenant_review', 'completed'], true);
$document = AgreementRenderer::render($agreement, $reveal);

admin_header('Agreement BL-AGR-' . sprintf('%05d', $id), 'agreements');
?>
<div class="belive-page-head">
    <div>
        <a href="/admin/agreements" class="review-back-link">← Agreements</a>
        <h1 style="margin-top:6px">BL-AGR-<?= sprintf('%05d', $id) ?> · <?= e($agreement['tenant_name'] ?: $agreement['wa_phone']) ?></h1>
        <p class="belive-muted" style="font-size:13.5px; margin-top:4px">
            <?= e($agreement['room_name'] ?? 'Room removed') ?> · <?= e($agreement['location'] ?? '') ?>
            · drafted by <?= e($agreement['generated_by_model'] ?? 'unknown model') ?>
        </p>
    </div>
    <span class="belive-badge <?= $agreement['status'] === 'completed' ? '' : ($stage['cancelled'] ? 'danger' : 'orange') ?>"><?= e($stage['label']) ?></span>
</div>

<ol class="agreement-track" aria-label="Agreement progress">
    <?php foreach (DigitalAgreement::STAGES as $i => $stageKey): ?>
        <?php $done = $i < $stage['index'] || $agreement['status'] === 'completed'; ?>
        <li class="<?= $done ? 'done' : ($i === $stage['index'] && !$stage['cancelled'] ? 'current' : '') ?>">
            <span class="agreement-track-dot"><?= $done ? '✓' : $i + 1 ?></span>
            <span class="agreement-track-label"><?= e(DigitalAgreement::STAGE_LABELS[$stageKey]) ?></span>
        </li>
    <?php endforeach; ?>
</ol>
<?php if ($stage['cancelled']): ?>
    <div class="belive-alert danger">This agreement was cancelled<?= $agreement['admin_review_note'] ? ': ' . e($agreement['admin_review_note']) : '.' ?></div>
<?php endif; ?>

<div class="review-listing-summary belive-card">
    <div><span class="belive-muted">Tenant</span><strong><?= e($agreement['tenant_name'] ?: $agreement['wa_phone']) ?></strong></div>
    <div><span class="belive-muted">Owner</span><strong><?= e($agreement['owner_name'] ?: 'No owner on the room') ?></strong></div>
    <div><span class="belive-muted">Term</span><strong>
        <?= $agreement['starts_on'] !== null && $agreement['ends_on'] !== null
            ? e(date('j M Y', strtotime($agreement['starts_on'])) . ' – ' . date('j M Y', strtotime($agreement['ends_on'])))
            : 'Not set (drafted before the workflow)' ?>
    </strong></div>
    <div><span class="belive-muted">Rent</span><strong>
        <?= $agreement['monthly_rent_rm'] !== null
            ? 'RM ' . e(number_format((float) $agreement['monthly_rent_rm'], 2)) . '/month'
            : 'Not on record' ?>
    </strong></div>
    <div><span class="belive-muted">Deposit</span><strong>RM <?= e(number_format((float) $agreement['deposit_rm'], 2)) ?></strong></div>
    <div><span class="belive-muted">Tenant access code</span><strong><code><?= e($agreement['access_code']) ?></code></strong></div>
</div>

<?php if ($agreement['tenant_note'] !== null && $agreement['status'] === 'admin_review'): ?>
    <div class="belive-alert warning">
        <strong>The tenant asked for a change before signing:</strong>
        <div><?= e($agreement['tenant_note']) ?></div>
    </div>
<?php endif; ?>

<div class="review-section-grid">
    <section class="belive-card review-section" aria-labelledby="doc-heading">
        <div class="review-section-head">
            <div>
                <h2 id="doc-heading">The agreement</h2>
                <p class="belive-muted">
                    <?= $ownerSigned
                        ? 'The owner\'s details are merged in. This is exactly what the tenant will sign.'
                        : 'The landlord\'s details are still placeholders — the owner fills them in when it reaches them.' ?>
                </p>
            </div>
        </div>

        <?php if ($agreement['status'] === 'draft'): ?>
            <form method="post" class="review-action-form">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="action" value="edit_draft">
                <input type="hidden" name="stage_version" value="<?= $version ?>">
                <label for="agreement_text">Draft body — edit before it goes to the owner</label>
                <textarea id="agreement_text" name="agreement_text" rows="20" class="agreement-draft-editor"><?= e($agreement['agreement_text']) ?></textarea>
                <p class="belive-muted" style="font-size:12px">
                    Leave the <code>{{LANDLORD_NAME}}</code>-style placeholders alone — they are filled from what the owner submits.
                </p>
                <button type="submit" class="belive-btn-secondary">Save draft</button>
            </form>
        <?php endif; ?>

        <details <?= $agreement['status'] === 'draft' ? '' : 'open' ?> class="agreement-preview">
            <summary>Preview the full document<?= $reveal ? '' : ' (landlord details masked until the owner signs)' ?></summary>
            <div class="agreement-document"><?= e($document) ?></div>
        </details>
    </section>

    <section class="belive-card review-section" aria-labelledby="party-heading">
        <div class="review-section-head">
            <div>
                <h2 id="party-heading">Landlord particulars</h2>
                <p class="belive-muted">Supplied by the owner, encrypted at rest.</p>
            </div>
            <span class="belive-badge <?= $ownerSigned ? '' : 'muted' ?>"><?= $ownerSigned ? 'signed' : 'not submitted' ?></span>
        </div>

        <?php if (!$ownerSigned): ?>
            <p class="belive-muted">Nothing yet. The owner supplies their name, NRIC, address, contact details and
            the bank account rent is paid into, then signs.</p>
        <?php else: ?>
            <dl class="agreement-particulars">
                <div><dt>Full name</dt><dd><?= e($particulars['name']) ?></dd></div>
                <div><dt>NRIC / passport</dt><dd><?= e($particulars['ic']) ?></dd></div>
                <div><dt>Email</dt><dd><?= e($particulars['email']) ?></dd></div>
                <div><dt>Phone</dt><dd><?= e($particulars['phone']) ?></dd></div>
                <div><dt>Address</dt><dd><?= e($particulars['address']) ?></dd></div>
                <div><dt>Bank</dt><dd><?= e($particulars['bank']) ?></dd></div>
                <div><dt>Account holder</dt><dd><?= e($particulars['account_holder']) ?></dd></div>
                <div><dt>Account number</dt><dd><?= e($particulars['account_no']) ?></dd></div>
            </dl>
            <p class="review-audit-line">
                Signed by <?= e($agreement['owner_signed_name']) ?> on <?= e($agreement['owner_signed_at']) ?>.
                Full details are in the document preview.
            </p>
            <?php if (($particulars['extra_terms'] ?? '') !== ''): ?>
                <div class="review-existing-note"><strong>Additional terms from the owner</strong><p><?= e($particulars['extra_terms']) ?></p></div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($agreement['acknowledged_at'] !== null): ?>
            <p class="review-audit-line">Tenant signed: <?= e($agreement['acknowledged_name']) ?> on <?= e($agreement['acknowledged_at']) ?>.</p>
        <?php endif; ?>
    </section>
</div>

<?php if (!in_array($agreement['status'], ['completed', 'cancelled'], true)): ?>
<section class="belive-card" aria-labelledby="actions-heading">
    <div class="review-section-head">
        <div>
            <h2 id="actions-heading">Your move</h2>
            <p class="belive-muted">
                <?= match ($agreement['status']) {
                    'draft' => 'Check the draft reads right, then send it to the owner for their details and signature.',
                    'owner_review' => 'Sitting with the owner. Nothing to do until they return it — or cancel if it should not proceed.',
                    'admin_review' => 'The owner signed and sent it back. Check their details, then release it to the tenant.',
                    'tenant_review' => 'Released to the tenant. They review and sign in their portal.',
                    default => '',
                } ?>
            </p>
        </div>
    </div>

    <div class="review-decision-forms">
        <?php if ($agreement['status'] === 'draft'): ?>
            <form method="post" class="review-action-form">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="stage_version" value="<?= $version ?>">
                <input type="hidden" name="action" value="send_owner">
                <label>Send to <?= e($agreement['owner_name'] ?: 'the owner') ?></label>
                <p class="belive-muted" style="font-size:13px">They complete their personal and bank details, then sign.</p>
                <button type="submit" class="belive-btn-primary">Send to owner</button>
            </form>
            <form method="post" class="review-action-form" data-confirm="Rewrite this draft from scratch? Your edits will be replaced.">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="stage_version" value="<?= $version ?>">
                <input type="hidden" name="action" value="regenerate">
                <label>Not happy with the wording?</label>
                <p class="belive-muted" style="font-size:13px">Runs the drafting model again over the same facts.</p>
                <button type="submit" class="belive-btn-ghost">Regenerate draft</button>
            </form>
        <?php endif; ?>

        <?php if ($agreement['status'] === 'admin_review'): ?>
            <form method="post" class="review-action-form">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="stage_version" value="<?= $version ?>">
                <input type="hidden" name="action" value="approve">
                <label for="approve-note">Review note <span class="belive-muted">(optional)</span></label>
                <textarea id="approve-note" name="note" rows="3" maxlength="500" placeholder="What did you check?"></textarea>
                <button type="submit" class="belive-btn-primary">Approve &amp; send to tenant</button>
            </form>
            <form method="post" class="review-action-form reject" data-confirm="Send back to the owner? Their signature is cleared and they must sign again.">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="stage_version" value="<?= $version ?>">
                <input type="hidden" name="action" value="return_owner">
                <label for="return-note">What must the owner correct?</label>
                <textarea id="return-note" name="note" rows="3" maxlength="500" required placeholder="Be specific — this is what they see"></textarea>
                <button type="submit" class="belive-btn-danger">Send back to owner</button>
            </form>
        <?php endif; ?>

        <form method="post" class="review-action-form reject" data-confirm="Cancel this agreement? It cannot be signed afterwards.">
            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="stage_version" value="<?= $version ?>">
            <input type="hidden" name="action" value="cancel">
            <label for="cancel-note">Cancel this agreement</label>
            <textarea id="cancel-note" name="note" rows="3" maxlength="500" required placeholder="Why is it being cancelled?"></textarea>
            <button type="submit" class="belive-btn-ghost" style="color:var(--belive-danger)">Cancel agreement</button>
        </form>
    </div>
</section>
<?php endif; ?>

<section class="belive-card" aria-labelledby="trail-heading">
    <div class="review-section-head">
        <div><h2 id="trail-heading">Hand-off trail</h2><p class="belive-muted">Every move this document made, and who made it.</p></div>
    </div>
    <ol class="agreement-trail">
        <?php foreach ($events as $event): ?>
            <li>
                <div class="agreement-trail-when"><?= e(date('j M Y, g:ia', strtotime((string) $event['created_at']))) ?></div>
                <div>
                    <strong><?= e(AgreementEvent::describe($event)) ?></strong>
                    <?php if ($event['note'] !== null): ?><div class="belive-muted"><?= e($event['note']) ?></div><?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
</section>
<?php admin_footer();
