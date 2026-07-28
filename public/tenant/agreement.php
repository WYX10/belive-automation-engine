<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Agreements\AgreementRenderer;
use App\Agreements\ESignatureHandler;
use App\Core\Auth;
use App\Models\DigitalAgreement;
use App\Models\RenewalOffer;
use App\Models\Room;
use App\Renewals\RenewalOfferManager;

require dirname(__DIR__) . '/_portal_layout.php';
$lead = require_tenant();

// Answering a renewal offer is its own action: it belongs to the tenant, not to
// the document, so it must not depend on there being an agreement to sign.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($_POST['do'] ?? '', ['accept_renewal', 'decline_renewal'], true)) {
    Auth::requireCsrf();
    $decision = $_POST['do'] === 'accept_renewal' ? 'accepted' : 'declined';

    try {
        RenewalOfferManager::respond(
            (int) $lead['id'],
            (int) ($_POST['offer_id'] ?? 0),
            $decision,
            $_POST['response_note'] ?? null
        );
        set_flash('success', $decision === 'accepted'
            ? 'Accepted — your owner can see it. BeLive will send you the renewal agreement to sign at that price; nothing about your current agreement changes until you both sign it.'
            : 'Declined — your owner can see it. Your current agreement runs to its end date as normal.');
    } catch (\InvalidArgumentException|\RuntimeException $e) {
        set_flash('danger', $e->getMessage());
    } catch (\Throwable $e) {
        error_log('[tenant renewal offer] ' . $e->getMessage());
        set_flash('danger', 'Your answer could not be saved. Please try again.');
    }

    header('Location: /tenant/agreement');
    exit;
}

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

// The renewal price the owner has offered, if any — plus the last answered one,
// so a tenant who already decided can still see what they decided.
$offer = RenewalOffer::openForTenant((int) $lead['id']);
$pastOffers = array_values(array_filter(
    RenewalOffer::forTenant((int) $lead['id']),
    static fn (array $row): bool => $offer === null || (int) $row['id'] !== (int) $offer['id']
));
$lastAnswered = $pastOffers[0] ?? null;

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

<?php if ($offer !== null): ?>
    <div class="belive-card renewal-offer-card state-offered" style="max-width:720px; margin-bottom:16px">
        <div class="renewal-offer-title">🎁 Your owner has offered you a renewal price</div>
        <p class="belive-muted" style="font-size:13.5px">
            Your term is nearly up. Stay on<?= $offer['room_name'] !== null ? ' in ' . e($offer['room_name']) : '' ?>
            and this is what you would pay instead.
        </p>
        <div class="renewal-price-compare">
            <div><span>You pay now</span><strong>RM <?= e(number_format((float) $offer['current_rent_rm'], 2)) ?></strong></div>
            <div class="promo"><span>Renewal price</span><strong>RM <?= e(number_format((float) $offer['promo_rent_rm'], 2)) ?></strong></div>
            <div><span>You save</span><strong>RM <?= e(number_format(RenewalOffer::monthlySaving($offer), 2)) ?>/mo</strong></div>
        </div>
        <div class="agreement-date-range" style="margin-top:14px">
            <div>
                <span>New term starts</span>
                <time datetime="<?= e((string) $offer['starts_on']) ?>"><?= e(date('j M Y', strtotime((string) $offer['starts_on']))) ?></time>
            </div>
            <div>
                <span>New term ends</span>
                <time datetime="<?= e((string) $offer['ends_on']) ?>"><?= e(date('j M Y', strtotime((string) $offer['ends_on']))) ?></time>
            </div>
        </div>
        <p class="belive-muted" style="font-size:13px; margin-top:10px">
            <?= e(Room::TENURE_LABELS[$offer['tenure']] ?? $offer['tenure']) ?> ·
            answer by <?= e(date('j M Y', strtotime((string) $offer['expires_on']))) ?>
        </p>
        <?php if ($offer['message'] !== null): ?>
            <p class="renewal-offer-message">Your owner says: “<?= e($offer['message']) ?>”</p>
        <?php endif; ?>

        <form method="post" action="/tenant/agreement" class="renewal-offer-actions">
            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
            <input type="hidden" name="offer_id" value="<?= (int) $offer['id'] ?>">
            <div class="belive-field">
                <label for="response_note">Anything to say back? <span class="belive-muted">(optional)</span></label>
                <textarea id="response_note" name="response_note" rows="2" maxlength="500"
                          placeholder="Your owner sees this with your answer"></textarea>
            </div>
            <div class="renewal-offer-buttons">
                <button type="submit" name="do" value="accept_renewal" class="belive-btn-primary">Accept this price</button>
                <button type="submit" name="do" value="decline_renewal" class="belive-btn-ghost">No thanks</button>
            </div>
        </form>
        <p class="belive-muted" style="font-size:12px; margin-top:12px">
            Accepting tells your owner you want to stay at this price. BeLive then prepares the renewal
            agreement for you both to sign — your current agreement is unchanged until that is signed, and
            declining leaves it running to its end date either way.
        </p>
    </div>
<?php elseif ($lastAnswered !== null): ?>
    <?php $lastState = RenewalOffer::state($lastAnswered); ?>
    <div class="belive-card renewal-offer-card state-<?= e($lastState) ?>" style="max-width:720px; margin-bottom:16px">
        <div class="renewal-offer-title">
            <?= match ($lastState) {
                'accepted' => '🎉 You accepted a renewal price',
                'declined' => '🙁 You declined a renewal price',
                'withdrawn' => '↩️ Your owner withdrew a renewal offer',
                default => '⌛ A renewal offer expired',
            } ?>
            — RM <?= e(number_format((float) $lastAnswered['promo_rent_rm'], 2)) ?>/month
        </div>
        <p class="belive-muted" style="font-size:13.5px">
            <?= e(Room::TENURE_LABELS[$lastAnswered['tenure']] ?? $lastAnswered['tenure']) ?> from
            <?= e(date('j M Y', strtotime((string) $lastAnswered['starts_on']))) ?>.
            <?= $lastState === 'accepted'
                ? 'BeLive is preparing the renewal agreement — it will appear here for your signature.'
                : 'Nothing changed about your current agreement.' ?>
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
            <?php /* CSS state classes are hyphenated; the timeline states are not. */ ?>
            <section class="agreement-timeline state-<?= e(str_replace('_', '-', $timeline['state'])) ?>" aria-labelledby="agreement-time-heading">
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
