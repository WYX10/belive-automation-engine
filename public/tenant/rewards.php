<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Auth;
use App\Models\Referral;
use App\Models\ReferralRedemption;
use App\Pipeline\Referral\ReferralLinkGenerator;

require dirname(__DIR__) . '/_portal_layout.php';
$lead = require_tenant();
$leadId = (int) $lead['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'redeem_rent_credit') {
    Auth::requireCsrf();
    try {
        ReferralRedemption::requestRentCredit($leadId);
        set_flash('success', 'Your RM' . RENT_REWARD_CREDIT_RM . ' rent credit request is now under review.');
    } catch (\RuntimeException $e) {
        set_flash('danger', $e->getMessage());
    } catch (\Throwable $e) {
        error_log('[rent reward] ' . $e->getMessage());
        set_flash('danger', 'Your rent credit request could not be saved. Please try again.');
    }
    header('Location: /tenant/rewards');
    exit;
}

$shareLink = ReferralLinkGenerator::linkFor($leadId);
$shareCode = strtoupper((string) basename((string) parse_url($shareLink, PHP_URL_PATH)));
$wallet = ReferralRedemption::walletForLead($leadId);
$referrals = Referral::forReferrer($leadId);
$redemptions = ReferralRedemption::forLead($leadId);
$activeRequest = null;
foreach ($redemptions as $redemption) {
    if (in_array($redemption['status'], ['requested', 'approved'], true)) {
        $activeRequest = $redemption;
        break;
    }
}
$pointsNeeded = max(0, RENT_REWARD_POINTS - $wallet['available_points']);
$rewardProgress = min(100, (int) round(($wallet['available_points'] / RENT_REWARD_POINTS) * 100));

portal_header('tenant', 'Rent rewards', 'rewards');
?>
<div class="portal-hero">
    <div class="tagline">Share BeLive. Lower your rent.</div>
    <h1>Rent rewards</h1>
    <p>Earn the room's referral points when a friend uses your code and their booking is confirmed.</p>
</div>

<div class="reward-summary-grid">
    <section class="belive-card reward-wallet" aria-labelledby="points-balance-heading">
        <div class="reward-eyebrow">Available balance</div>
        <div class="reward-points" id="points-balance-heading">
            <strong><?= number_format($wallet['available_points']) ?></strong>
            <span>points</span>
        </div>
        <p><?= number_format($wallet['earned_points']) ?> earned from <?= number_format($wallet['confirmed_referrals']) ?> confirmed referral<?= $wallet['confirmed_referrals'] === 1 ? '' : 's' ?>.</p>
    </section>

    <section class="belive-card rent-reward-card" aria-labelledby="rent-credit-heading">
        <div class="reward-eyebrow">Rent prize</div>
        <h2 id="rent-credit-heading">RM<?= number_format(RENT_REWARD_CREDIT_RM) ?> rent credit</h2>
        <p class="belive-muted"><?= number_format(RENT_REWARD_POINTS) ?> points per redemption. Requests are reviewed before the credit is applied.</p>
        <div class="reward-progress-copy">
            <span><?= number_format($wallet['available_points']) ?> / <?= number_format(RENT_REWARD_POINTS) ?> points</span>
            <strong><?= $rewardProgress ?>%</strong>
        </div>
        <div class="belive-bar reward-progress" role="progressbar" aria-label="Progress toward rent credit"
             aria-valuemin="0" aria-valuemax="<?= RENT_REWARD_POINTS ?>" aria-valuenow="<?= min($wallet['available_points'], RENT_REWARD_POINTS) ?>">
            <span style="width:<?= $rewardProgress ?>%"></span>
        </div>

        <?php if ($activeRequest !== null): ?>
            <button class="belive-btn-secondary reward-redeem-button" type="button" disabled>Request under review</button>
            <p class="reward-helper">Your points are reserved while the RM<?= number_format((float) $activeRequest['rent_credit_amount']) ?> request is reviewed.</p>
        <?php elseif ($pointsNeeded > 0): ?>
            <button class="belive-btn-secondary reward-redeem-button" type="button" disabled>Redeem rent credit</button>
            <p class="reward-helper">Earn <?= number_format($pointsNeeded) ?> more points from confirmed friend bookings to unlock this reward.</p>
        <?php else: ?>
            <form method="post" action="/tenant/rewards">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <input type="hidden" name="do" value="redeem_rent_credit">
                <button class="belive-btn-secondary reward-redeem-button" type="submit">Redeem RM<?= number_format(RENT_REWARD_CREDIT_RM) ?> rent credit</button>
            </form>
            <p class="reward-helper">This reserves <?= number_format(RENT_REWARD_POINTS) ?> points and creates a reviewable request.</p>
        <?php endif; ?>
    </section>
</div>

<section class="belive-card reward-share-card" aria-labelledby="share-code-heading">
    <div>
        <div class="reward-eyebrow">Your referral code</div>
        <h2 id="share-code-heading"><code><?= e($shareCode) ?></code></h2>
        <p class="belive-muted">Send this unique link to one friend. When they enquire and book, you receive the referral points set for their room.</p>
    </div>
    <div class="reward-copy-control">
        <label for="referral-link">Referral link</label>
        <div>
            <input id="referral-link" type="text" readonly value="<?= e($shareLink) ?>">
            <button class="belive-btn-ghost" id="copy-referral-link" type="button">Copy link</button>
        </div>
        <span class="reward-copy-status" id="copy-referral-status" aria-live="polite"></span>
    </div>
</section>

<div class="reward-history-grid">
    <section class="belive-card" aria-labelledby="referral-activity-heading">
        <div class="belive-card-title" id="referral-activity-heading">Referral activity</div>
        <?php if ($referrals === []): ?>
            <p class="belive-muted">No referral activity yet. Share your link to get started.</p>
        <?php else: ?>
            <div class="reward-history-list">
                <?php foreach (array_slice($referrals, 0, 8) as $referral): ?>
                    <?php
                    $referralState = $referral['reward_status'] === 'credited'
                        ? ['label' => 'Points earned', 'class' => '']
                        : ($referral['referred_lead_id'] !== null
                            ? ['label' => 'Friend joined', 'class' => 'orange']
                            : ['label' => 'Ready to share', 'class' => 'muted']);
                    ?>
                    <div class="reward-history-row">
                        <div>
                            <code><?= e($referral['referral_code']) ?></code>
                            <span><?= e(date('j M Y', strtotime($referral['created_at']))) ?></span>
                            <?php if ($referral['reward_room_name'] !== null): ?>
                                <span><?= e(($referral['reward_property_name'] ?: 'Property') . ' · ' . $referral['reward_room_name']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="reward-history-value">
                            <?php if ((int) $referral['reward_points'] > 0): ?><strong>+<?= (int) $referral['reward_points'] ?> pts</strong><?php endif; ?>
                            <span class="belive-badge <?= e($referralState['class']) ?>"><?= e($referralState['label']) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="belive-card" aria-labelledby="redemption-history-heading">
        <div class="belive-card-title" id="redemption-history-heading">Rent credit requests</div>
        <?php if ($redemptions === []): ?>
            <p class="belive-muted">No redemptions yet. Your first rent credit unlocks at <?= number_format(RENT_REWARD_POINTS) ?> points.</p>
        <?php else: ?>
            <div class="reward-history-list">
                <?php foreach (array_slice($redemptions, 0, 8) as $redemption): ?>
                    <?php
                    $redemptionClass = match ($redemption['status']) {
                        'applied' => '',
                        'rejected' => 'danger',
                        default => 'orange',
                    };
                    ?>
                    <div class="reward-history-row">
                        <div>
                            <strong>RM<?= number_format((float) $redemption['rent_credit_amount']) ?> rent credit</strong>
                            <span><?= e(date('j M Y', strtotime($redemption['requested_at']))) ?></span>
                        </div>
                        <div class="reward-history-value">
                            <?php if ($redemption['status'] === 'rejected'): ?>
                                <strong><?= (int) $redemption['points_spent'] ?> pts returned</strong>
                            <?php else: ?>
                                <strong>−<?= (int) $redemption['points_spent'] ?> pts</strong>
                            <?php endif; ?>
                            <span class="belive-badge <?= e($redemptionClass) ?>"><?= e($redemption['status']) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<script>
(() => {
    const button = document.getElementById('copy-referral-link');
    const input = document.getElementById('referral-link');
    const status = document.getElementById('copy-referral-status');
    button.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(input.value);
            status.textContent = 'Referral link copied.';
            button.textContent = 'Copied';
        } catch (error) {
            input.select();
            status.textContent = 'Select and copy the highlighted link.';
        }
    });
})();
</script>
<?php portal_footer();
