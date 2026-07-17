<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Agreements\ESignatureHandler;
use App\Core\Auth;
use App\Models\DigitalAgreement;

require dirname(__DIR__) . '/_portal_layout.php';
$lead = require_tenant();

$agreements = DigitalAgreement::forLead((int) $lead['id']);
$agreement = $agreements[0] ?? null;
$timeline = $agreement !== null ? DigitalAgreement::timeline($agreement) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    if ($agreement === null) {
        set_flash('danger', 'No agreement is available to acknowledge.');
        header('Location: /tenant/agreement');
        exit;
    }
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

        <?php if ($timeline !== null): ?>
            <?php
            $timelineCopy = match ($timeline['state']) {
                'upcoming' => [
                    'badge' => 'Upcoming agreement',
                    'value' => (string) $timeline['days_until_start'],
                    'label' => $timeline['days_until_start'] === 1 ? 'day until start' : 'days until start',
                ],
                'expired' => [
                    'badge' => 'Agreement expired',
                    'value' => (string) $timeline['days_since_end'],
                    'label' => $timeline['days_since_end'] === 1 ? 'day since expiry' : 'days since expiry',
                ],
                'ending_today' => ['badge' => 'Ends today', 'value' => '0', 'label' => 'days left'],
                'ending_soon' => [
                    'badge' => 'Ending soon',
                    'value' => (string) $timeline['days_remaining'],
                    'label' => $timeline['days_remaining'] === 1 ? 'day left' : 'days left',
                ],
                default => [
                    'badge' => 'Agreement active',
                    'value' => (string) $timeline['days_remaining'],
                    'label' => $timeline['days_remaining'] === 1 ? 'day left' : 'days left',
                ],
            };
            ?>
            <section class="agreement-timeline state-<?= e($timeline['state']) ?>" aria-labelledby="agreement-time-heading">
                <div class="agreement-time-summary">
                    <span class="agreement-time-badge"><?= e($timelineCopy['badge']) ?></span>
                    <div class="agreement-time-count" id="agreement-time-heading">
                        <strong><?= e($timelineCopy['value']) ?></strong>
                        <span><?= e($timelineCopy['label']) ?></span>
                    </div>
                </div>
                <div class="agreement-date-range">
                    <div>
                        <span>Start date</span>
                        <time datetime="<?= e($timeline['starts_on']) ?>"><?= e(date('j M Y', strtotime($timeline['starts_on']))) ?></time>
                    </div>
                    <div>
                        <span>End date</span>
                        <time datetime="<?= e($timeline['ends_on']) ?>"><?= e(date('j M Y', strtotime($timeline['ends_on']))) ?></time>
                    </div>
                </div>
                <div class="agreement-progress" role="progressbar" aria-label="Agreement term elapsed"
                     aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $timeline['progress_percent'] ?>">
                    <span style="width:<?= (int) $timeline['progress_percent'] ?>%"></span>
                </div>
            </section>
        <?php else: ?>
            <div class="belive-alert" style="margin-bottom:16px">
                Agreement dates are not available for this earlier agreement. Ask the owner to issue an updated agreement with a confirmed start date.
            </div>
        <?php endif; ?>

        <div class="agreement-text"><?= e($agreement['agreement_text']) ?></div>

        <?php if ($agreement['status'] !== 'acknowledged'): ?>
            <form method="post" action="/tenant/agreement" style="margin-top:16px">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
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
