<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

/**
 * Owner view of who is renting, for how long, and what to do when a term is
 * nearly up. Rental periods are real: they come off the signed agreement
 * (digital_agreements.starts_on / ends_on), never from a booking date.
 *
 * In the last DigitalAgreement::ENDING_SOON_DAYS of a tenancy the owner can
 * offer that tenant a promotional rent for a new term. The tenant answers it in
 * their own portal — accepting records that they want to stay at that price, and
 * BeLive drafts the renewal agreement afterwards. Nothing here changes a signed
 * document.
 */

use App\Core\Auth;
use App\Models\DigitalAgreement;
use App\Models\RenewalOffer;
use App\Models\Room;
use App\Renewals\RenewalOfferManager;

require dirname(__DIR__) . '/_portal_layout.php';
$owner = require_owner();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    try {
        $action = $_POST['do'] ?? '';
        if ($action === 'offer_renewal') {
            $offer = RenewalOfferManager::offer($owner, (int) ($_POST['agreement_id'] ?? 0), $_POST);
            set_flash('success', sprintf(
                'Offer sent — RM %s/month for %s. Your tenant sees it in their portal and can accept or decline until %s.',
                number_format((float) $offer['promo_rent_rm'], 2),
                Room::TENURE_LABELS[$offer['tenure']] ?? $offer['tenure'],
                date('j M Y', strtotime((string) $offer['expires_on']))
            ));
        } elseif ($action === 'withdraw_renewal') {
            RenewalOfferManager::withdraw($owner, (int) ($_POST['offer_id'] ?? 0));
            set_flash('success', 'Offer withdrawn. Your tenant can no longer accept it — send a new price whenever you are ready.');
        } else {
            throw new RuntimeException('Choose a valid renewal action.');
        }
    } catch (\PDOException $e) {
        error_log('[owner tenancies database] ' . $e->getMessage());
        set_flash('danger', 'The offer could not be saved. Please check the details and try again.');
    } catch (\InvalidArgumentException|\RuntimeException $e) {
        set_flash('danger', $e->getMessage());
    } catch (\Throwable $e) {
        error_log('[owner tenancies] ' . $e->getMessage());
        set_flash('danger', 'The offer could not be saved. Please try again.');
    }
    header('Location: /owner/tenancies');
    exit;
}

$tenancies = DigitalAgreement::tenanciesForOwner($owner);
$offers = RenewalOffer::latestForAgreements(array_map(static fn (array $t): int => (int) $t['id'], $tenancies));

// Four buckets, in the order an owner cares about them: whoever is closest to
// leaving first, tenancies already over last.
$groups = [
    'ending'   => ['title' => '🔔 Ending soon — offer a renewal price', 'rows' => []],
    'active'   => ['title' => '✅ Running', 'rows' => []],
    'upcoming' => ['title' => '📅 Not started yet', 'rows' => []],
    'ended'    => ['title' => '📕 Ended', 'rows' => []],
];

foreach ($tenancies as $tenancy) {
    $timeline = DigitalAgreement::timeline($tenancy);
    $row = [
        'tenancy'  => $tenancy,
        'timeline' => $timeline,
        'offer'    => $offers[(int) $tenancy['id']] ?? null,
        'rent'     => RenewalOfferManager::currentRent($tenancy),
    ];
    $bucket = match ($timeline['state'] ?? '') {
        'ending_soon', 'ending_today' => 'ending',
        'upcoming' => 'upcoming',
        'expired'  => 'ended',
        default    => 'active',
    };
    $groups[$bucket]['rows'][] = $row;
}

$endingCount = count($groups['ending']['rows']);
$openOffers = count(array_filter($offers, static fn (array $o): bool => RenewalOffer::isOpen($o)));

/** Owner-side wording for each tenancy state. */
$timelineCopy = static function (array $timeline): array {
    return match ($timeline['state']) {
        'upcoming' => [
            'badge' => 'Starts soon',
            'value' => (string) $timeline['days_until_start'],
            'label' => $timeline['days_until_start'] === 1 ? 'day until it starts' : 'days until it starts',
        ],
        'expired' => [
            'badge' => 'Tenancy ended',
            'value' => (string) $timeline['days_since_end'],
            'label' => $timeline['days_since_end'] === 1 ? 'day since it ended' : 'days since it ended',
        ],
        'ending_today' => ['badge' => 'Ends today', 'value' => '0', 'label' => 'days left'],
        'ending_soon' => [
            'badge' => 'Ending soon',
            'value' => (string) $timeline['days_remaining'],
            'label' => $timeline['days_remaining'] === 1 ? 'day left' : 'days left',
        ],
        default => [
            'badge' => 'Running',
            'value' => (string) $timeline['days_remaining'],
            'label' => $timeline['days_remaining'] === 1 ? 'day left' : 'days left',
        ],
    };
};

portal_header('owner', 'Tenants & renewals', 'tenancies');
?>
<div class="portal-hero">
    <div class="tagline">Keep the good ones. Fill the rest.</div>
    <h1>Tenants &amp; renewals</h1>
    <p>Every rental period you have running, counted down to the day — and, in the last
    <?= DigitalAgreement::ENDING_SOON_DAYS ?> days, one button to offer that tenant a better price to stay.</p>
</div>

<div class="owner-portfolio-stats" aria-label="Tenancy summary">
    <div><strong><?= count($tenancies) ?></strong><span>tenancies</span></div>
    <div><strong><?= $endingCount ?></strong><span>ending within <?= DigitalAgreement::ENDING_SOON_DAYS ?> days</span></div>
    <div><strong><?= $openOffers ?></strong><span>offers awaiting an answer</span></div>
</div>

<?php if ($tenancies === []): ?>
    <div class="belive-card owner-property-empty">
        <h2>No tenancies yet</h2>
        <p class="belive-muted">A rental period appears here once you and your tenant have both signed the
        agreement BeLive prepared. Anything still being signed is on your
        <a href="/owner/agreements">Agreements</a> page.</p>
    </div>
<?php endif; ?>

<?php foreach ($groups as $key => $group): ?>
    <?php if ($group['rows'] === []) { continue; } ?>
    <section class="owner-tenancy-group" aria-labelledby="tenancy-group-<?= e($key) ?>">
        <h2 id="tenancy-group-<?= e($key) ?>"><?= e($group['title']) ?> <span class="belive-badge muted"><?= count($group['rows']) ?></span></h2>

        <?php foreach ($group['rows'] as $row): ?>
            <?php
            $tenancy = $row['tenancy'];
            $timeline = $row['timeline'];
            $offer = $row['offer'];
            $offerState = $offer !== null ? RenewalOffer::state($offer) : null;
            $agreementId = (int) $tenancy['id'];
            // timeline() returns null only when the stored dates make no sense
            // (end before start) — the card still shows the tenancy, without a
            // countdown it cannot honestly draw.
            $state = $timeline['state'] ?? null;
            // CSS state classes are hyphenated; the timeline states are not.
            $stateClass = $state !== null ? 'state-' . str_replace('_', '-', $state) : '';
            $canOffer = RenewalOfferManager::isOfferable($timeline) && $offerState !== 'offered';
            ?>
            <article class="belive-card owner-tenancy-card" aria-labelledby="tenancy-<?= $agreementId ?>-heading">
                <header class="owner-tenancy-heading">
                    <div>
                        <div class="reward-eyebrow">BL-AGR-<?= sprintf('%05d', $agreementId) ?></div>
                        <h3 id="tenancy-<?= $agreementId ?>-heading"><?= e($tenancy['tenant_name'] ?: $tenancy['wa_phone']) ?></h3>
                        <p class="belive-muted">
                            <?= e($tenancy['property_name'] ?: 'Property') ?>
                            <?php if ($tenancy['house_name'] !== null): ?> · <?= e($tenancy['house_name']) ?><?php endif; ?>
                            <?php if ($tenancy['room_name'] !== null): ?> · <?= e($tenancy['room_name']) ?><?php endif; ?>
                            <?php if ($tenancy['room_code'] !== null): ?> (<?= e($tenancy['room_code']) ?>)<?php endif; ?>
                        </p>
                    </div>
                    <div class="owner-tenancy-rent">
                        <strong>RM <?= e(number_format($row['rent'], 2)) ?></strong>
                        <span>per month<?= $tenancy['tenure'] !== null ? ' · ' . e(Room::TENURE_LABELS[$tenancy['tenure']] ?? $tenancy['tenure']) : '' ?></span>
                    </div>
                </header>

                <?php if ($timeline === null): ?>
                    <div class="belive-alert warning">
                        This agreement's start and end dates do not read as a rental period. Ask BeLive to reissue it
                        with a confirmed term before offering a renewal.
                    </div>
                <?php else: ?>
                <?php $copy = $timelineCopy($timeline); ?>
                <section class="agreement-timeline <?= e($stateClass) ?>" aria-label="Rental period">
                    <div class="agreement-time-summary">
                        <span class="agreement-time-badge"><?= e($copy['badge']) ?></span>
                        <div class="agreement-time-count">
                            <strong><?= e($copy['value']) ?></strong>
                            <span><?= e($copy['label']) ?></span>
                        </div>
                    </div>
                    <div class="agreement-date-range">
                        <div>
                            <span>Rental period starts</span>
                            <time datetime="<?= e($timeline['starts_on']) ?>"><?= e(date('j M Y', strtotime($timeline['starts_on']))) ?></time>
                        </div>
                        <div>
                            <span>Rental period ends</span>
                            <time datetime="<?= e($timeline['ends_on']) ?>"><?= e(date('j M Y', strtotime($timeline['ends_on']))) ?></time>
                        </div>
                    </div>
                    <div class="agreement-progress" role="progressbar" aria-label="Rental period elapsed"
                         aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $timeline['progress_percent'] ?>">
                        <span style="width:<?= (int) $timeline['progress_percent'] ?>%"></span>
                    </div>
                </section>
                <?php endif; ?>

                <?php if ($offer !== null && $offerState === 'offered'): ?>
                    <div class="renewal-offer-card state-offered">
                        <div class="renewal-offer-title">🎁 Offer waiting for an answer</div>
                        <div class="renewal-price-compare">
                            <div><span>They pay now</span><strong>RM <?= e(number_format((float) $offer['current_rent_rm'], 2)) ?></strong></div>
                            <div class="promo"><span>Your offer</span><strong>RM <?= e(number_format((float) $offer['promo_rent_rm'], 2)) ?></strong></div>
                            <div><span>They save</span><strong>RM <?= e(number_format(RenewalOffer::monthlySaving($offer), 2)) ?>/mo</strong></div>
                        </div>
                        <p class="belive-muted">
                            <?= e(Room::TENURE_LABELS[$offer['tenure']] ?? $offer['tenure']) ?> ·
                            <?= e(date('j M Y', strtotime((string) $offer['starts_on']))) ?>
                            to <?= e(date('j M Y', strtotime((string) $offer['ends_on']))) ?> ·
                            expires <?= e(date('j M Y', strtotime((string) $offer['expires_on']))) ?>
                        </p>
                        <?php if ($offer['message'] !== null): ?>
                            <p class="renewal-offer-message">“<?= e($offer['message']) ?>”</p>
                        <?php endif; ?>
                        <form method="post" action="/owner/tenancies">
                            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                            <input type="hidden" name="do" value="withdraw_renewal">
                            <input type="hidden" name="offer_id" value="<?= (int) $offer['id'] ?>">
                            <button class="belive-btn-ghost" type="submit">Withdraw this offer</button>
                        </form>
                    </div>
                <?php elseif ($offer !== null): ?>
                    <div class="renewal-offer-card state-<?= e($offerState) ?>">
                        <div class="renewal-offer-title">
                            <?= match ($offerState) {
                                'accepted' => '🎉 Offer accepted',
                                'declined' => '🙁 Offer declined',
                                'withdrawn' => '↩️ Offer withdrawn',
                                default => '⌛ Offer expired unanswered',
                            } ?>
                            — RM <?= e(number_format((float) $offer['promo_rent_rm'], 2)) ?>/month
                        </div>
                        <p class="belive-muted">
                            <?= e(Room::TENURE_LABELS[$offer['tenure']] ?? $offer['tenure']) ?> from
                            <?= e(date('j M Y', strtotime((string) $offer['starts_on']))) ?>
                            <?php if ($offer['responded_at'] !== null): ?>
                                · answered <?= e(date('j M Y', strtotime((string) $offer['responded_at']))) ?>
                            <?php endif; ?>
                        </p>
                        <?php if ($offer['response_note'] !== null): ?>
                            <p class="renewal-offer-message">Tenant said: “<?= e($offer['response_note']) ?>”</p>
                        <?php endif; ?>
                        <?php if ($offerState === 'accepted'): ?>
                            <p class="belive-muted" style="font-size:12.5px">
                                BeLive drafts the renewal agreement at this price and sends it to you to sign,
                                the same way the first one was prepared. The current agreement is untouched
                                until both of you sign the new one.
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($canOffer): ?>
                    <?php [$previewStart, $previewEnd] = RenewalOfferManager::newTerm((string) $tenancy['ends_on'], '12_month'); ?>
                    <details class="owner-add-room renewal-offer-form">
                        <summary><?= $offer !== null ? 'Offer a different price' : 'Offer a renewal price' ?></summary>
                        <form method="post" action="/owner/tenancies" class="owner-room-form">
                            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                            <input type="hidden" name="do" value="offer_renewal">
                            <input type="hidden" name="agreement_id" value="<?= $agreementId ?>">

                            <div class="belive-field">
                                <label for="tenure-<?= $agreementId ?>">Renew for</label>
                                <select id="tenure-<?= $agreementId ?>" name="tenure" required>
                                    <?php foreach (Room::TENURES as $tenure): ?>
                                        <option value="<?= e($tenure) ?>" <?= $tenure === '12_month' ? 'selected' : '' ?>>
                                            <?= e(Room::TENURE_LABELS[$tenure]) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="hint">The new term starts <?= e(date('j M Y', strtotime($previewStart))) ?>,
                                    the day after this one ends — 12 months would run to <?= e(date('j M Y', strtotime($previewEnd))) ?>.</div>
                            </div>
                            <div class="belive-field">
                                <label for="promo-<?= $agreementId ?>">Promotional rent (RM / month)</label>
                                <input id="promo-<?= $agreementId ?>" name="promo_rent_rm" type="number" min="1"
                                       max="<?= e(number_format($row['rent'] - 0.01, 2, '.', '')) ?>" step="0.01" required
                                       aria-describedby="promo-<?= $agreementId ?>-hint">
                                <div id="promo-<?= $agreementId ?>-hint" class="hint">
                                    They pay RM <?= e(number_format($row['rent'], 2)) ?> now — a promotional price has to be below that.
                                </div>
                            </div>
                            <div class="belive-field">
                                <label for="expires-<?= $agreementId ?>">Offer expires</label>
                                <input id="expires-<?= $agreementId ?>" name="expires_on" type="date"
                                       value="<?= e((string) $tenancy['ends_on']) ?>"
                                       min="<?= e(date('Y-m-d')) ?>" max="<?= e((string) $tenancy['ends_on']) ?>" required>
                                <div class="hint">Defaults to the day the current tenancy ends.</div>
                            </div>
                            <div class="belive-field owner-form-wide">
                                <label for="message-<?= $agreementId ?>">Message to your tenant <span class="belive-muted">(optional)</span></label>
                                <textarea id="message-<?= $agreementId ?>" name="message" rows="2" maxlength="500"
                                          placeholder="You've been a great tenant — stay another year at this price."></textarea>
                            </div>
                            <button class="belive-btn-primary owner-form-wide" type="submit">Send this offer</button>
                        </form>
                    </details>
                <?php elseif ($state === 'active'): ?>
                    <p class="owner-tenancy-note belive-muted">
                        You can offer a renewal price in the last <?= DigitalAgreement::ENDING_SOON_DAYS ?> days of this tenancy —
                        from <?= e(date('j M Y', strtotime($timeline['ends_on'] . ' -' . DigitalAgreement::ENDING_SOON_DAYS . ' day'))) ?>.
                    </p>
                <?php elseif ($state === 'upcoming'): ?>
                    <p class="owner-tenancy-note belive-muted">This tenancy has not started yet — nothing to renew.</p>
                <?php elseif ($state === 'expired' && $offerState !== 'accepted'): ?>
                    <p class="owner-tenancy-note belive-muted">
                        This tenancy is over. Renewal prices are offered before the end date; to fill the room again,
                        set it back to available on <a href="/owner/properties">Properties &amp; rooms</a>.
                    </p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
<?php endforeach; ?>
<?php portal_footer();
