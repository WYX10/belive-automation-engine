<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Models\Booking;
use App\Models\ElectricBill;
use App\Models\RenewalOffer;

require dirname(__DIR__) . '/_portal_layout.php';
$lead = require_tenant();
$room = tenant_room($lead);
$bookings = Booking::forLead((int) $lead['id']);
$electric = ElectricBill::summaryForTenant((int) $lead['id']);
$renewalOffer = RenewalOffer::openForTenant((int) $lead['id']);

portal_header('tenant', 'My stay', 'dashboard');
?>
<div class="portal-hero">
    <div class="tagline">Live Smarter, Stay Better.</div>
    <h1>Hi <?= e($lead['name'] ?: 'there') ?> 👋</h1>
    <p>Everything about your BeLive stay — bookings, your room, and the trust tools that protect you.</p>
</div>

<?php if ($renewalOffer !== null): ?>
    <a class="belive-card renewal-offer-teaser" href="/tenant/agreement">
        <div>
            <div class="renewal-offer-title">🎁 Your owner has offered you a renewal price</div>
            <p class="belive-muted">RM <?= e(number_format((float) $renewalOffer['promo_rent_rm'], 2)) ?>/month
            to stay on — RM <?= e(number_format(RenewalOffer::monthlySaving($renewalOffer), 2)) ?> a month less than you pay now.
            Answer by <?= e(date('j M Y', strtotime((string) $renewalOffer['expires_on']))) ?>.</p>
        </div>
        <span class="belive-btn-primary">See the offer</span>
    </a>
<?php endif; ?>

<div class="belive-row">
    <div class="belive-col">
        <div class="belive-card">
            <div class="belive-card-title">🏠 Your room</div>
            <?php if ($room === null): ?>
                <p class="belive-muted">No room attached yet — Eve will match you during your next chat.</p>
            <?php else: ?>
                <?php $roomPrices = \App\Models\Room::prices((int) $room['id']); ?>
                <strong style="font-size:16px"><?= e($room['name']) ?></strong>
                <div class="belive-muted" style="font-size:13.5px">
                    <?= e($room['location']) ?> · <?= e($room['room_type']) ?> room · RM 0 deposit
                </div>
                <div style="font-size:13.5px; margin-top:6px">
                    <?php foreach (\App\Models\Room::TENURES as $tenure): ?>
                        <?php if (isset($roomPrices[$tenure])): ?>
                            <span class="belive-badge <?= $roomPrices[$tenure]['is_best_value'] ? '' : 'muted' ?>" style="margin-right:4px">
                                <?= e(\App\Models\Room::TENURE_LABELS[$tenure]) ?>: RM <?= e(number_format($roomPrices[$tenure]['price'])) ?>/mo<?= $roomPrices[$tenure]['is_best_value'] ? ' ★' : '' ?>
                            </span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <ul class="belive-check-list" style="margin-top:10px">
                    <?php foreach (\App\Models\Room::amenities((int) $room['id']) as $feature): ?>
                        <li><?= e($feature) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="belive-card" style="margin-top:16px">
            <div class="belive-card-title">📅 Your viewings</div>
            <?php if ($bookings === []): ?>
                <p class="belive-muted">No viewings booked.</p>
            <?php else: ?>
                <?php foreach ($bookings as $booking): ?>
                    <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--belive-line); font-size:14px">
                        <span><?= e(date('D, j M Y · g:ia', strtotime($booking['viewing_datetime']))) ?></span>
                        <span class="belive-badge <?= $booking['status'] === 'confirmed' ? '' : 'orange' ?>"><?= e($booking['status']) ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="belive-card" style="margin-top:16px">
            <div class="belive-card-title">⚡ Electricity</div>
            <?php if ($electric['bills'] === []): ?>
                <p class="belive-muted">Your room has its own meter, so you only ever pay for what your room uses.
                Your bills appear here once the first billing period closes.</p>
            <?php else: ?>
                <div class="electric-glance">
                    <?php if ($electric['outstanding_count'] > 0): ?>
                        <div class="electric-glance-figure">
                            <strong>RM <?= e(number_format($electric['outstanding_rm'], 2)) ?></strong>
                            <span>outstanding<?= $electric['next_due_on'] !== null ? ' · due ' . e(date('j M', strtotime($electric['next_due_on']))) : '' ?></span>
                        </div>
                        <span class="belive-badge <?= $electric['overdue_count'] > 0 ? 'danger' : 'orange' ?>">
                            <?= $electric['overdue_count'] > 0 ? 'overdue' : 'unpaid' ?>
                        </span>
                    <?php else: ?>
                        <div class="electric-glance-figure">
                            <strong><?= e(number_format((float) $electric['latest']['units_kwh'], 1)) ?></strong>
                            <span>kWh last period</span>
                        </div>
                        <span class="belive-badge">all settled</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div style="margin-top:14px">
                <a class="belive-btn-ghost" href="/tenant/electric">⚡ My electric bill</a>
            </div>
        </div>
    </div>

    <div class="belive-col">
        <div class="belive-card">
            <div class="belive-card-title">🛡 Your protection, built in</div>
            <p class="belive-muted" style="font-size:13.5px; margin-bottom:12px">Renting shouldn't be a gamble. Four tools, zero extra cost:</p>
            <div style="display:grid; gap:10px">
                <a class="belive-btn-ghost" href="/tenant/rewards">Rent rewards from referrals</a>
                <a class="belive-btn-ghost" href="/tenant/listing_verification">✅ Verified listing card</a>
                <a class="belive-btn-ghost" href="/tenant/move_in_log">📷 Move-in condition log</a>
                <a class="belive-btn-ghost" href="/tenant/agreement">📄 Digital agreement</a>
                <a class="belive-btn-ghost" href="/tenant/fair_pricing">⚖ Fair pricing guard</a>
            </div>
            <div style="margin-top:16px">
                <a class="belive-btn-secondary" style="width:100%; justify-content:center" href="<?= e(eve_whatsapp_link()) ?>" target="_blank" rel="noopener">💬 Anything else? Chat with Eve</a>
            </div>
        </div>
    </div>
</div>
<?php portal_footer();
