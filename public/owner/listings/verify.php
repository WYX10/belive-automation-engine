<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Auth;
use App\Core\Database;
use App\Models\Room;
use App\Models\VerifiedListing;
use App\Verification\ListingVerifier;

require dirname(dirname(__DIR__)) . '/_portal_layout.php';
$owner = require_owner();

$roomId = (int) ($_GET['room_id'] ?? $_POST['room_id'] ?? 0);
$room = Room::find($roomId);
if ($room === null || $room['owner_name'] !== $owner) {
    set_flash('danger', 'That room is not on your account.');
    header('Location: /owner/listings');
    exit;
}

$isAdminToo = Auth::check(); // admin reviewing in the same browser session

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';

    if ($do === 'submit_doc') {
        $docUrl = trim($_POST['doc_url'] ?? '');
        if (filter_var($docUrl, FILTER_VALIDATE_URL)) {
            ListingVerifier::recordOwnershipDoc($roomId, $docUrl);
            set_flash('success', 'Ownership document submitted — pending BeLive admin review.');
        } else {
            set_flash('danger', 'Give a valid document URL.');
        }
    } elseif ($do === 'submit_gps') {
        $lat = (float) ($_POST['gps_lat'] ?? 0);
        $lng = (float) ($_POST['gps_lng'] ?? 0);
        if ($lat !== 0.0 && $lng !== 0.0) {
            ListingVerifier::recordGps($roomId, $lat, $lng, false);
            set_flash('success', 'Coordinates submitted — pending admin map check.');
        } else {
            set_flash('danger', 'Give both latitude and longitude.');
        }
    } elseif ($do === 'admin_confirm_ownership' && $isAdminToo) {
        ListingVerifier::confirmOwnership($roomId, ($_POST['confirmed'] ?? '') === '1');
        set_flash('success', 'Ownership review recorded.');
    } elseif ($do === 'admin_confirm_gps' && $isAdminToo) {
        $row = VerifiedListing::ensure($roomId);
        ListingVerifier::recordGps($roomId, (float) $row['gps_lat'], (float) $row['gps_lng'], ($_POST['confirmed'] ?? '') === '1');
        set_flash('success', 'GPS review recorded.');
    }

    header('Location: /owner/listings/verify?room_id=' . $roomId);
    exit;
}

$verification = VerifiedListing::ensure($roomId);
$scamFlags = json_decode($verification['scam_flags'] ?? '[]', true) ?: [];

portal_header('owner', 'Verification', 'listings');
?>
<div class="portal-hero">
    <h1>Verification — <?= e($room['name']) ?></h1>
    <p>Verified listings convert better: tenants see the badge and the checks behind it.</p>
</div>

<div class="belive-row">
    <div class="belive-col">
        <div class="belive-card">
            <div class="belive-card-title">📄 Ownership document</div>
            <?php if ($verification['ownership_doc_path']): ?>
                <p style="font-size:13.5px">Submitted: <a href="<?= e($verification['ownership_doc_path']) ?>" target="_blank" rel="noopener">view document</a></p>
                <p style="margin-top:6px">
                    <?php if ((int) $verification['ownership_verified'] === 1): ?>
                        <span class="belive-badge">✓ confirmed by BeLive admin</span>
                    <?php else: ?>
                        <span class="belive-badge orange">pending admin review</span>
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="room_id" value="<?= $roomId ?>">
                    <input type="hidden" name="do" value="submit_doc">
                    <div class="belive-field">
                        <label>Document URL (title deed / SPA / utility bill)</label>
                        <input type="url" name="doc_url" required placeholder="https://drive.google.com/…">
                        <div class="hint">Reviewed manually by BeLive admin — this is a review flag, not an eKYC API.</div>
                    </div>
                    <button type="submit" class="belive-btn-primary">Submit for review</button>
                </form>
            <?php endif; ?>

            <?php if ($isAdminToo && $verification['ownership_doc_path'] && (int) $verification['ownership_verified'] !== 1): ?>
                <form method="post" style="margin-top:10px; display:flex; gap:8px">
                    <input type="hidden" name="room_id" value="<?= $roomId ?>">
                    <input type="hidden" name="do" value="admin_confirm_ownership">
                    <input type="hidden" name="confirmed" value="1">
                    <button type="submit" class="belive-btn-secondary" style="font-size:13px">[Admin] Confirm ownership</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="belive-col">
        <div class="belive-card">
            <div class="belive-card-title">📍 Location match</div>
            <?php if ($verification['gps_lat']): ?>
                <p style="font-size:13.5px">
                    Coordinates: <?= e($verification['gps_lat']) ?>, <?= e($verification['gps_lng']) ?>
                    · <a href="https://www.openstreetmap.org/?mlat=<?= e($verification['gps_lat']) ?>&mlon=<?= e($verification['gps_lng']) ?>#map=17/<?= e($verification['gps_lat']) ?>/<?= e($verification['gps_lng']) ?>" target="_blank" rel="noopener">open map</a>
                </p>
                <p style="margin-top:6px">
                    <?php if ((int) $verification['gps_matched'] === 1): ?>
                        <span class="belive-badge">✓ matches listed address</span>
                    <?php else: ?>
                        <span class="belive-badge orange">pending admin map check</span>
                    <?php endif; ?>
                </p>
                <?php if ($isAdminToo && (int) $verification['gps_matched'] !== 1): ?>
                    <form method="post" style="margin-top:10px">
                        <input type="hidden" name="room_id" value="<?= $roomId ?>">
                        <input type="hidden" name="do" value="admin_confirm_gps">
                        <input type="hidden" name="confirmed" value="1">
                        <button type="submit" class="belive-btn-secondary" style="font-size:13px">[Admin] Confirm match</button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="room_id" value="<?= $roomId ?>">
                    <input type="hidden" name="do" value="submit_gps">
                    <div style="display:flex; gap:10px">
                        <div class="belive-field" style="flex:1"><label>Latitude</label><input type="text" name="gps_lat" required placeholder="3.2010"></div>
                        <div class="belive-field" style="flex:1"><label>Longitude</label><input type="text" name="gps_lng" required placeholder="101.7180"></div>
                    </div>
                    <button type="submit" class="belive-btn-primary">Submit coordinates</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="belive-card" style="margin-top:16px">
            <div class="belive-card-title">🤖 AI scam screen</div>
            <?php if ($verification['scam_checked_at'] === null): ?>
                <p class="belive-muted" style="font-size:13.5px">Not screened yet — run it from <a href="/owner/listings">My listings</a>.</p>
            <?php elseif ($scamFlags === []): ?>
                <span class="belive-badge">✓ clean — screened <?= e($verification['scam_checked_at']) ?></span>
            <?php else: ?>
                <?php foreach ($scamFlags as $flag): ?>
                    <div class="belive-alert <?= ($flag['severity'] ?? '') === 'high' ? 'danger' : 'warning' ?>" style="font-size:13px">
                        <strong><?= e($flag['pattern'] ?? '') ?></strong> (<?= e($flag['severity'] ?? '') ?>) — <?= e($flag['detail'] ?? '') ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="belive-card" style="margin-top:16px; text-align:center">
    <?php if ((int) $verification['verified_badge'] === 1): ?>
        <span class="verified-badge-big">✓ This listing carries the verified badge</span>
    <?php else: ?>
        <span class="verified-badge-big unverified">Badge appears when ownership + location are confirmed and no high-severity flags remain</span>
    <?php endif; ?>
</div>
<?php portal_footer();
