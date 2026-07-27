<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Agreements\AgreementRenderer;
use App\Agreements\ESignatureHandler;
use App\Core\Auth;
use App\Models\DigitalAgreement;

require dirname(__DIR__) . '/_portal_layout.php';
$lead = require_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    $pending = DigitalAgreement::visibleToTenant((int) $lead['id']);
    $version = filter_var($_POST['stage_version'] ?? null, FILTER_VALIDATE_INT);

    if ($pending === null || $version === false || $version === null) {
        set_flash('danger', 'No agreement is available to sign right now.');
    } elseif (($_POST['do'] ?? '') === 'query') {
        $result = ESignatureHandler::requestChange((int) $pending['id'], $_POST['note'] ?? '', $version);
        set_flash($result['ok'] ? 'success' : 'danger', $result['ok']
            ? 'Sent to BeLive — they will look at it and come back to you.'
            : $result['error']);
    } else {
        $result = ESignatureHandler::sign(
            (int) $pending['id'],
            $_POST['typed_name'] ?? '',
            ($_POST['confirm'] ?? '') === '1',
            $version
        );
        set_flash($result['ok'] ? 'success' : 'danger', $result['ok']
            ? 'Signed — a timestamped record is now on file for you and the owner.'
            : $result['error']);
    }

    header('Location: /tenant/agreement');
    exit;
}

$all = DigitalAgreement::forLead((int) $lead['id']);
$latest = $all[0] ?? null;
$agreement = DigitalAgreement::visibleToTenant((int) $lead['id']);
$agreement = $agreement !== null ? DigitalAgreement::withContext((int) $agreement['id']) : null;
$timeline = $agreement !== null ? DigitalAgreement::timeline($agreement) : null;
$inPreparation = $latest !== null
    && in_array($latest['status'], ['draft', 'owner_review', 'admin_review'], true)
    ? DigitalAgreement::stage($latest['status'])
    : null;

portal_header('tenant', 'My agreement', 'agreement');
?>
<div class="portal-hero">
    <div class="tagline">Everything in writing. Nothing hidden.</div>
    <h1>Digital agreement</h1>
    <p>Your tenancy terms in plain language, signed digitally with a timestamp.</p>
</div>

<?php if ($inPreparation !== null): ?>
    <div class="belive-card" style="max-width:720px; margin-bottom:16px">
        <div class="belive-card-title">🕒 Your agreement is being prepared</div>
        <p class="belive-muted">
            <?= $inPreparation['holder'] === 'owner'
                ? 'It is with the owner right now — they add their details and sign it first.'
                : 'BeLive is preparing it. You will see it here the moment it is ready for you.' ?>
        </p>
        <p class="belive-muted" style="font-size:12.5px">
            You only ever sign a document that the owner has already signed and BeLive has checked.
        </p>
    </div>
<?php endif; ?>

<div class="belive-card" style="max-width:720px">
    <?php if ($agreement === null): ?>
        <?php if ($inPreparation === null): ?>
            <p class="belive-muted">No agreement yet — it is drafted once your booking is settled. You'll see it
            here the moment it's ready for you.</p>
        <?php endif; ?>
    <?php else: ?>
        <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:12px">
            <div class="belive-card-title" style="margin:0">📄 BL-AGR-<?= sprintf('%05d', (int) $agreement['id']) ?></div>
            <?php if ($agreement['status'] === 'completed'): ?>
                <span class="belive-badge">✓ signed by <?= e($agreement['acknowledged_name']) ?> · <?= e($agreement['acknowledged_at']) ?></span>
            <?php else: ?>
                <span class="belive-badge orange">ready for your signature</span>
            <?php endif; ?>
        </div>

        <?php if ($agreement['owner_signed_at'] !== null): ?>
            <div class="belive-alert" style="margin-bottom:16px">
                Signed by the owner, <?= e($agreement['owner_signed_name']) ?>, on
                <?= e(date('j M Y', strtotime((string) $agreement['owner_signed_at']))) ?>, and checked by BeLive.
            </div>
        <?php endif; ?>

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
                Agreement dates are not available for this earlier agreement. Ask BeLive to issue an updated agreement with a confirmed start date.
            </div>
        <?php endif; ?>

        <div class="agreement-text"><?= e(AgreementRenderer::render($agreement, true)) ?></div>

        <?php if ($agreement['status'] !== 'completed'): ?>
            <form method="post" action="/tenant/agreement" style="margin-top:16px">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <input type="hidden" name="stage_version" value="<?= (int) $agreement['stage_version'] ?>">
                <input type="hidden" name="do" value="sign">
                <div class="belive-field">
                    <label for="typed_name">Type your full name to sign</label>
                    <input id="typed_name" name="typed_name" type="text" required placeholder="<?= e($lead['name'] ?? '') ?>">
                </div>
                <label style="display:flex; gap:8px; align-items:flex-start; font-size:13.5px; margin-bottom:14px">
                    <input type="checkbox" name="confirm" value="1" style="margin-top:3px">
                    <span>I have read the agreement above and agree to its terms.</span>
                </label>
                <button type="submit" class="belive-btn-secondary">Sign agreement</button>
            </form>

            <details class="agreement-preview" style="margin-top:16px">
                <summary>Something not right? Ask for a change instead</summary>
                <form method="post" action="/tenant/agreement">
                    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                    <input type="hidden" name="stage_version" value="<?= (int) $agreement['stage_version'] ?>">
                    <input type="hidden" name="do" value="query">
                    <div class="belive-field">
                        <label for="note">What needs changing?</label>
                        <textarea id="note" name="note" rows="3" maxlength="500" required
                                  placeholder="Tell us what looks wrong — nothing is signed until you're happy"></textarea>
                    </div>
                    <button type="submit" class="belive-btn-ghost">Send to BeLive</button>
                </form>
            </details>
        <?php endif; ?>

        <p class="belive-muted" style="font-size:12px; margin-top:14px">
            You and the owner each sign by typing your own full name, recorded with a timestamp — a clear, dated
            record that both sides saw the same terms. It is not a cryptographic e-signature, and the agreement
            still needs stamping under the Stamp Act 1949.
        </p>
    <?php endif; ?>
</div>
<?php portal_footer();
