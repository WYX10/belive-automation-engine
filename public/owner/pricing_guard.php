<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Database;
use App\Pricing\FairPricingGuard;

require dirname(__DIR__) . '/_portal_layout.php';
$owner = require_owner();

$rooms = Database::run('SELECT * FROM rooms WHERE owner_name = ? ORDER BY area, name', [$owner])->fetchAll();

portal_header('owner', 'Pricing guard', 'pricing');
?>
<div class="portal-hero">
    <div class="tagline">Priced right, rented faster.</div>
    <h1>AI fair pricing</h1>
    <p>Each listing benchmarked against comparable BeLive rooms — spot over-pricing that slows conversion and suspicious-cheap signals that hurt trust.</p>
</div>

<?php foreach ($rooms as $room): ?>
    <?php $assessment = FairPricingGuard::assess((int) $room['id']); ?>
    <div class="belive-card" style="margin-bottom:14px">
        <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:baseline">
            <div>
                <strong><?= e($room['name']) ?></strong>
                <span class="belive-muted" style="font-size:13px"> — <?= e($room['area']) ?> · <?= e($room['room_type']) ?></span>
            </div>
            <div style="font-family:var(--font-head); font-weight:700">RM <?= e(number_format((float) $room['price'])) ?>/mo</div>
        </div>

        <?php
        $tone = match ($assessment['verdict']) {
            'fair'             => 'success',
            'above_market'     => 'warning',
            'suspiciously_low' => 'danger',
            default            => 'warning',
        };
        ?>
        <div class="belive-alert <?= $tone ?>" style="margin:10px 0 0; font-size:13.5px"><?= e($assessment['message']) ?></div>

        <?php if ($assessment['verdict'] === 'above_market' && $assessment['average'] !== null): ?>
            <p class="belive-muted" style="font-size:13px; margin-top:8px">
                💡 Suggestion: repricing toward RM <?= e(number_format($assessment['average'])) ?> puts you inside
                the range Eve converts fastest — higher occupancy usually beats a higher sticker price.
            </p>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<?php if ($rooms === []): ?>
    <div class="belive-card"><p class="belive-muted">No listings yet.</p></div>
<?php endif; ?>
<?php portal_footer();
