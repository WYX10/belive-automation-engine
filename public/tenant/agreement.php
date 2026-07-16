<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Agreements\ESignatureHandler;
use App\Models\DigitalAgreement;

require dirname(__DIR__) . '/_portal_layout.php';
$lead = require_tenant();

$agreements = DigitalAgreement::forLead((int) $lead['id']);
$agreement = $agreements[0] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $agreement !== null) {
    $result = ESignatureHandler::acknowledge(
        (int) $agreement['id'],
        $_POST['typed_name'] ?? '',
        ($_POST['confirm'] ?? '') === '1'
    );
    set_flash($result['ok'] ? 'success' : 'danger', $result['ok']
        ? 'Agreement acknowledged — a timestamped record is now on file for both sides.'
        : $result['error']);
    header('Location: /tenant/agreement');
    exit;
}

portal_header('tenant', 'My agreement', 'agreement');
?>
<div class="portal-hero">
    <div class="tagline">Everything in writing. Nothing hidden.</div>
    <h1>Digital agreement</h1>
    <p>Your tenancy terms in plain language, acknowledged digitally with a timestamp.</p>
</div>

<div class="belive-card" style="max-width:720px">
    <?php if ($agreement === null): ?>
        <p class="belive-muted">No agreement issued yet — the owner generates it once your booking is
        settled. You'll see it here the moment it's ready.</p>
    <?php else: ?>
        <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:12px">
            <div class="belive-card-title" style="margin:0">📄 Agreement #<?= (int) $agreement['id'] ?></div>
            <?php if ($agreement['status'] === 'acknowledged'): ?>
                <span class="belive-badge">✓ acknowledged by <?= e($agreement['acknowledged_name']) ?> · <?= e($agreement['acknowledged_at']) ?></span>
            <?php else: ?>
                <span class="belive-badge orange">awaiting your acknowledgement</span>
            <?php endif; ?>
        </div>

        <div class="agreement-text"><?= e($agreement['agreement_text']) ?></div>

        <?php if ($agreement['status'] !== 'acknowledged'): ?>
            <form method="post" action="/tenant/agreement" style="margin-top:16px">
                <div class="belive-field">
                    <label for="typed_name">Type your full name to acknowledge</label>
                    <input id="typed_name" name="typed_name" type="text" required placeholder="<?= e($lead['name'] ?? '') ?>">
                </div>
                <label style="display:flex; gap:8px; align-items:flex-start; font-size:13.5px; margin-bottom:14px">
                    <input type="checkbox" name="confirm" value="1" style="margin-top:3px">
                    <span>I have read the agreement above and acknowledge its terms.</span>
                </label>
                <button type="submit" class="belive-btn-secondary">Acknowledge agreement</button>
            </form>
        <?php endif; ?>

        <p class="belive-muted" style="font-size:12px; margin-top:14px">
            This is a digital acknowledgement (typed name + timestamp), not a cryptographic e-signature —
            it creates a clear, dated record that both sides saw the same terms.
        </p>
    <?php endif; ?>
</div>
<?php portal_footer();
