<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Models\MoveInLog;

require dirname(__DIR__) . '/_portal_layout.php';
$lead = require_tenant();
$room = tenant_room($lead);
$photos = $room !== null ? MoveInLog::forRoom((int) $room['id']) : [];

portal_header('tenant', 'Move-in log', 'move_in');
?>
<div class="portal-hero">
    <div class="tagline">Zero disputes. Zero stress.</div>
    <h1>Move-in condition log</h1>
    <p>Timestamped photos of your room's condition before you moved in — evidence both sides can rely on when you move out.</p>
</div>

<div class="belive-card">
    <?php if ($room === null): ?>
        <p class="belive-muted">Your move-in log appears here once a room is attached to your booking.</p>
    <?php elseif ($photos === []): ?>
        <p class="belive-muted">No condition photos logged yet for <?= e($room['name']) ?>. The owner or BeLive
        admin uploads them before your move-in date — ask Eve if they're missing.</p>
    <?php else: ?>
        <div class="belive-card-title">📷 <?= e($room['name']) ?> — <?= count($photos) ?> logged photo(s)</div>
        <div class="movein-grid">
            <?php foreach ($photos as $photo): ?>
                <div class="movein-item">
                    <img src="<?= e($photo['photo_path']) ?>" alt="<?= e($photo['caption'] ?: 'Room condition photo') ?>" loading="lazy">
                    <div class="stamp">
                        🕐 <?= e($photo['taken_at']) ?> · by <?= e($photo['uploaded_by']) ?>
                        <?php if ($photo['caption']): ?><br><?= e($photo['caption']) ?><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php portal_footer();
