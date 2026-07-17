<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Auth;
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    $do = $_POST['do'] ?? '';

    if ($do === 'submit_doc') {
        $docUrl = trim($_POST['doc_url'] ?? '');
        if (filter_var($docUrl, FILTER_VALIDATE_URL)) {
            ListingVerifier::recordOwnershipDoc($roomId, $docUrl);
            set_flash('success', 'Ownership document submitted - pending BeLive admin review.');
        } else {
            set_flash('danger', 'Give a valid document URL.');
        }
    } elseif ($do === 'submit_gps') {
        $lat = filter_var($_POST['gps_lat'] ?? null, FILTER_VALIDATE_FLOAT);
        $lng = filter_var($_POST['gps_lng'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($lat !== false && $lng !== false && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
            ListingVerifier::recordGps($roomId, (float) $lat, (float) $lng, false);
            set_flash('success', 'Coordinates submitted - pending admin map check.');
        } else {
            set_flash('danger', 'Give valid latitude and longitude coordinates.');
        }
    }

    header('Location: /owner/listings/verify?room_id=' . $roomId);
    exit;
}

$verification = VerifiedListing::ensure($roomId);
$scamFlags = json_decode($verification['scam_flags'] ?? '[]', true) ?: [];
$ownershipStatus = $verification['ownership_review_status'] ?? 'not_submitted';
$gpsStatus = $verification['gps_review_status'] ?? 'not_submitted';
$tone = static fn (string $status): string => match ($status) {
    'approved' => '',
    'pending' => 'orange',
    'rejected' => 'danger',
    default => 'muted',
};

portal_header('owner', 'Verification', 'listings');
?>
<div class="portal-hero">
    <h1>Verification - <?= e($room['name']) ?></h1>
    <p>Submit evidence once. BeLive admins review it from a separate, auditable review queue.</p>
</div>

<div class="belive-row">
    <div class="belive-col">
        <div class="belive-card">
            <div class="belive-card-title">Ownership document</div>
            <?php if ($verification['ownership_doc_path']): ?>
                <p style="font-size:13.5px">Submitted: <a href="<?= e($verification['ownership_doc_path']) ?>" target="_blank" rel="noopener noreferrer">view document</a></p>
                <p style="margin-top:6px"><span class="belive-badge <?= $tone($ownershipStatus) ?>"><?= e(str_replace('_', ' ', $ownershipStatus)) ?></span></p>
                <?php if ($verification['ownership_reviewed_at'] !== null): ?>
                    <p class="belive-muted" style="font-size:12px; margin-top:7px">Reviewed <?= e($verification['ownership_reviewed_at']) ?></p>
                <?php endif; ?>
                <?php if ($verification['ownership_review_note'] !== null): ?>
                    <div class="belive-alert <?= $ownershipStatus === 'rejected' ? 'danger' : 'success' ?>" style="margin-top:12px">
                        <strong>Admin feedback:</strong> <?= e($verification['ownership_review_note']) ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (!$verification['ownership_doc_path'] || $ownershipStatus === 'rejected'): ?>
                <form method="post" style="margin-top:<?= $verification['ownership_doc_path'] ? '14px' : '0' ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                    <input type="hidden" name="room_id" value="<?= $roomId ?>">
                    <input type="hidden" name="do" value="submit_doc">
                    <div class="belive-field">
                        <label for="doc-url"><?= $ownershipStatus === 'rejected' ? 'Replacement document URL' : 'Document URL' ?> (title deed / SPA / utility bill)</label>
                        <input id="doc-url" type="url" name="doc_url" required placeholder="https://drive.google.com/...">
                        <div class="hint">Make sure the link can be opened by the BeLive admin.</div>
                    </div>
                    <button type="submit" class="belive-btn-primary"><?= $ownershipStatus === 'rejected' ? 'Resubmit for review' : 'Submit for review' ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="belive-col">
        <div class="belive-card">
            <div class="belive-card-title">Location match</div>
            <?php if ($verification['gps_lat'] !== null && $verification['gps_lng'] !== null): ?>
                <p style="font-size:13.5px">
                    Coordinates: <?= e($verification['gps_lat']) ?>, <?= e($verification['gps_lng']) ?>
                    · <a href="https://www.openstreetmap.org/?mlat=<?= e($verification['gps_lat']) ?>&mlon=<?= e($verification['gps_lng']) ?>#map=17/<?= e($verification['gps_lat']) ?>/<?= e($verification['gps_lng']) ?>" target="_blank" rel="noopener noreferrer">open map</a>
                </p>
                <p style="margin-top:6px"><span class="belive-badge <?= $tone($gpsStatus) ?>"><?= e(str_replace('_', ' ', $gpsStatus)) ?></span></p>
                <?php if ($verification['gps_reviewed_at'] !== null): ?>
                    <p class="belive-muted" style="font-size:12px; margin-top:7px">Reviewed <?= e($verification['gps_reviewed_at']) ?></p>
                <?php endif; ?>
                <?php if ($verification['gps_review_note'] !== null): ?>
                    <div class="belive-alert <?= $gpsStatus === 'rejected' ? 'danger' : 'success' ?>" style="margin-top:12px">
                        <strong>Admin feedback:</strong> <?= e($verification['gps_review_note']) ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($verification['gps_lat'] === null || $verification['gps_lng'] === null || $gpsStatus === 'rejected'): ?>
                <form method="post" style="margin-top:<?= $verification['gps_lat'] !== null ? '14px' : '0' ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                    <input type="hidden" name="room_id" value="<?= $roomId ?>">
                    <input type="hidden" name="do" value="submit_gps">
                    <div style="display:flex; gap:10px; flex-wrap:wrap">
                        <div class="belive-field" style="flex:1; min-width:150px"><label for="gps-lat">Latitude</label><input id="gps-lat" type="number" step="any" min="-90" max="90" name="gps_lat" required placeholder="3.2010"></div>
                        <div class="belive-field" style="flex:1; min-width:150px"><label for="gps-lng">Longitude</label><input id="gps-lng" type="number" step="any" min="-180" max="180" name="gps_lng" required placeholder="101.7180"></div>
                    </div>
                    <button type="submit" class="belive-btn-primary"><?= $gpsStatus === 'rejected' ? 'Resubmit coordinates' : 'Submit coordinates' ?></button>
                </form>
            <?php endif; ?>
        </div>

        <div class="belive-card" style="margin-top:16px">
            <div class="belive-card-title">AI scam screen</div>
            <?php if ($verification['scam_checked_at'] === null): ?>
                <p class="belive-muted" style="font-size:13.5px">Not screened yet - run it from <a href="/owner/listings">My listings</a>.</p>
            <?php elseif ($scamFlags === []): ?>
                <span class="belive-badge">clean - screened <?= e($verification['scam_checked_at']) ?></span>
            <?php else: ?>
                <?php foreach ($scamFlags as $flag): ?>
                    <div class="belive-alert <?= ($flag['severity'] ?? '') === 'high' ? 'danger' : 'warning' ?>" style="font-size:13px">
                        <strong><?= e($flag['pattern'] ?? '') ?></strong> (<?= e($flag['severity'] ?? '') ?>) - <?= e($flag['detail'] ?? '') ?>
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
        <span class="verified-badge-big unverified">Badge appears when ownership and location are approved and no high-severity flags remain</span>
    <?php endif; ?>
</div>
<?php portal_footer();
