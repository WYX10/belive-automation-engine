<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Database;
use App\Models\VerifiedListing;
use App\Verification\MoveInLogger;
use App\Verification\ScamDetector;

require dirname(dirname(__DIR__)) . '/_portal_layout.php';
$owner = require_owner();

// Actions: run scam screen · add a move-in photo (URL or upload).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $roomId = (int) ($_POST['room_id'] ?? 0);
    $owned = Database::run('SELECT COUNT(*) FROM rooms WHERE id = ? AND owner_name = ?', [$roomId, $owner])->fetchColumn() > 0;

    if (!$owned) {
        set_flash('danger', 'That room is not on your account.');
    } elseif (($_POST['do'] ?? '') === 'scam_screen') {
        try {
            $flags = ScamDetector::screen($roomId);
            set_flash($flags === [] ? 'success' : 'warning', $flags === []
                ? 'AI screen complete — no scam patterns flagged.'
                : 'AI screen flagged ' . count($flags) . ' pattern(s) for admin review — see listing verification.');
        } catch (\Throwable $e) {
            set_flash('danger', 'Screen failed: ' . $e->getMessage());
        }
    } elseif (($_POST['do'] ?? '') === 'movein_photo') {
        try {
            if (!empty($_FILES['photo_file']['name'])) {
                MoveInLogger::addUpload($roomId, null, $_FILES['photo_file'], trim($_POST['caption'] ?? ''), 'owner');
            } elseif (trim($_POST['photo_url'] ?? '') !== '') {
                MoveInLogger::addByUrl($roomId, null, trim($_POST['photo_url']), trim($_POST['caption'] ?? ''), 'owner');
            } else {
                throw new RuntimeException('Give a photo URL or choose a file.');
            }
            set_flash('success', 'Move-in condition photo logged with timestamp.');
        } catch (\Throwable $e) {
            set_flash('danger', $e->getMessage());
        }
    }
    header('Location: /owner/listings');
    exit;
}

$rooms = Database::run(
    "SELECT r.*, COALESCE(rp.price, 0) AS monthly_price
     FROM rooms r
     LEFT JOIN room_pricing rp ON rp.room_id = r.id AND rp.tenure = 'monthly'
     WHERE r.owner_name = ? ORDER BY r.location, r.name",
    [$owner]
)->fetchAll();

portal_header('owner', 'My listings', 'listings');
?>
<div class="portal-hero">
    <div class="tagline">More Income, Less Hassle</div>
    <h1>My listings</h1>
    <p>Standardised listings with verification, AI scam screening and move-in condition logs.</p>
</div>

<?php foreach ($rooms as $room): ?>
    <?php
    $verification = VerifiedListing::forRoom((int) $room['id']);
    $moveInCount = (int) Database::run('SELECT COUNT(*) FROM move_in_logs WHERE room_id = ?', [$room['id']])->fetchColumn();
    ?>
    <div class="belive-card" style="margin-bottom:16px">
        <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:baseline">
            <div>
                <strong style="font-size:16px"><?= e($room['name']) ?></strong>
                <span class="belive-muted" style="font-size:13.5px"> — <?= e($room['location']) ?> · <?= e($room['room_type']) ?> · RM <?= e(number_format((float) $room['monthly_price'])) ?>/mo flexible</span>
            </div>
            <div style="display:flex; gap:6px; flex-wrap:wrap">
                <?php if ($verification !== null && (int) $verification['verified_badge'] === 1): ?>
                    <span class="belive-badge">✓ verified</span>
                <?php else: ?>
                    <span class="belive-badge muted">unverified</span>
                <?php endif; ?>
                <span class="belive-badge <?= $room['status'] === 'available' ? 'orange' : '' ?>"><?= $room['status'] === 'available' ? 'listed' : e($room['status']) ?></span>
                <span class="belive-badge muted"><?= $moveInCount ?> move-in photo(s)</span>
            </div>
        </div>

        <div style="display:flex; gap:8px; margin-top:14px; flex-wrap:wrap; align-items:center">
            <a class="belive-btn-ghost" style="padding:6px 12px; font-size:13px" href="/owner/listings/verify?room_id=<?= (int) $room['id'] ?>">Verification status</a>
            <form method="post" action="/owner/listings" style="display:inline">
                <input type="hidden" name="room_id" value="<?= (int) $room['id'] ?>">
                <input type="hidden" name="do" value="scam_screen">
                <button type="submit" class="belive-btn-ghost" style="padding:6px 12px; font-size:13px">Run AI scam screen</button>
            </form>
        </div>

        <details style="margin-top:12px">
            <summary style="font-size:13.5px; cursor:pointer; color:var(--belive-teal-dark)">+ Log a move-in condition photo</summary>
            <form method="post" action="/owner/listings" enctype="multipart/form-data" style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end">
                <input type="hidden" name="room_id" value="<?= (int) $room['id'] ?>">
                <input type="hidden" name="do" value="movein_photo">
                <div class="belive-field" style="flex:2; min-width:200px; margin:0">
                    <label>Photo URL</label>
                    <input type="url" name="photo_url" placeholder="https://…">
                </div>
                <div class="belive-field" style="flex:1; min-width:160px; margin:0">
                    <label>or upload</label>
                    <input type="file" name="photo_file" accept="image/*">
                </div>
                <div class="belive-field" style="flex:2; min-width:180px; margin:0">
                    <label>Caption</label>
                    <input type="text" name="caption" placeholder="e.g. Wardrobe interior, no damage">
                </div>
                <button type="submit" class="belive-btn-primary" style="padding:9px 14px">Log photo</button>
            </form>
        </details>
    </div>
<?php endforeach; ?>

<?php if ($rooms === []): ?>
    <div class="belive-card"><p class="belive-muted">No listings under your account yet.</p></div>
<?php endif; ?>
<?php portal_footer();
