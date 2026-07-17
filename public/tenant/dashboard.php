<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Models\Booking;

require dirname(__DIR__) . '/_portal_layout.php';
$lead = require_tenant();
$room = tenant_room($lead);
$bookings = Booking::forLead((int) $lead['id']);

portal_header('tenant', 'My stay', 'dashboard');
?>
<div class="portal-hero">
    <div class="tagline">Live Smarter, Stay Better.</div>
    <h1>Hi <?= e($lead['name'] ?: 'there') ?> 👋</h1>
    <p>Everything about your BeLive stay — bookings, your room, and the trust tools that protect you.</p>
</div>

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
