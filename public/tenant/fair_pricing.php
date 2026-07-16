<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Pricing\FairPricingGuard;

require dirname(__DIR__) . '/_portal_layout.php';
$lead = require_tenant();
$room = tenant_room($lead);
$assessment = $room !== null ? FairPricingGuard::assess((int) $room['id']) : null;

portal_header('tenant', 'Fair pricing', 'pricing');
?>
<div class="portal-hero">
    <div class="tagline">Pay what's fair — and know it.</div>
    <h1>Fair pricing guard</h1>
    <p>Your rent, benchmarked against comparable BeLive rooms in the same area and type.</p>
</div>

<div class="belive-card" style="max-width:640px">
    <?php if ($room === null || $assessment === null): ?>
        <p class="belive-muted">Your price check appears here once a room is attached to your booking.</p>
    <?php else: ?>
        <strong style="font-size:16px"><?= e($room['name']) ?></strong>
        <div class="belive-muted" style="font-size:13.5px; margin-bottom:16px"><?= e($room['location']) ?> · <?= e($room['room_type']) ?> room · flexible-monthly benchmark</div>

        <div class="belive-stat-grid">
            <div class="belive-stat">
                <div class="belive-stat-icon">💰</div>
                <div>
                    <div class="belive-stat-number">RM <?= e(number_format($assessment['price'])) ?></div>
                    <div class="belive-stat-label">your monthly rent</div>
                </div>
            </div>
            <div class="belive-stat">
                <div class="belive-stat-icon teal">📊</div>
                <div>
                    <div class="belive-stat-number teal"><?= $assessment['average'] !== null ? 'RM ' . e(number_format($assessment['average'])) : '—' ?></div>
                    <div class="belive-stat-label">comparable average (<?= (int) $assessment['sample_size'] ?> rooms)</div>
                </div>
            </div>
        </div>

        <?php
        $tone = match ($assessment['verdict']) {
            'fair'              => 'success',
            'above_market'      => 'warning',
            'suspiciously_low'  => 'danger',
            default             => 'warning',
        };
        ?>
        <div class="belive-alert <?= $tone ?>" style="margin-top:16px">
            <?php if ($assessment['verdict'] === 'fair'): ?>✓ <strong>Fair price.</strong><?php endif; ?>
            <?= e($assessment['message']) ?>
        </div>

        <p class="belive-muted" style="font-size:12px">
            Benchmark source: live BeLive inventory, same area and room type (widened to same room type
            across areas when local data is thin). A guide, not a guarantee.
        </p>
    <?php endif; ?>
</div>
<?php portal_footer();
