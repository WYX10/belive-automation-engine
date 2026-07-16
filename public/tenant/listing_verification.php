<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Models\VerifiedListing;
use App\Verification\ListingVerifier;

require dirname(__DIR__) . '/_portal_layout.php';
$lead = require_tenant();
$room = tenant_room($lead);

$verification = $room !== null ? VerifiedListing::forRoom((int) $room['id']) : null;
$checklist = $verification !== null ? ListingVerifier::checklist($verification) : [];
$scamFlags = $verification !== null ? (json_decode($verification['scam_flags'] ?? '[]', true) ?: []) : [];

portal_header('tenant', 'Verified listing', 'verification');
?>
<div class="portal-hero">
    <div class="tagline">The Smarter Way to Rent</div>
    <h1>Verified listing card</h1>
    <p>Independent checks on your listing — so what you saw is what you get.</p>
</div>

<div class="belive-card" style="max-width:640px">
    <?php if ($room === null): ?>
        <p class="belive-muted">Once a room is attached to your booking, its verification card appears here.</p>
    <?php elseif ($verification === null): ?>
        <strong><?= e($room['name']) ?></strong>
        <div class="belive-muted" style="font-size:13.5px; margin-bottom:10px"><?= e($room['area']) ?> · <?= e($room['room_type']) ?> room</div>
        <span class="verified-badge-big unverified">⏳ Verification in progress</span>
        <p class="belive-muted" style="font-size:13.5px; margin-top:10px">The owner hasn't submitted verification documents for this listing yet.</p>
    <?php else: ?>
        <strong style="font-size:16px"><?= e($room['name']) ?></strong>
        <div class="belive-muted" style="font-size:13.5px; margin-bottom:12px"><?= e($room['area']) ?> · <?= e($room['room_type']) ?> room · RM <?= e(number_format((float) $room['price'])) ?>/month</div>

        <?php if ((int) $verification['verified_badge'] === 1): ?>
            <span class="verified-badge-big">✓ Verified listing</span>
            <div class="belive-muted" style="font-size:12.5px; margin-top:6px">Verified <?= e($verification['verified_at']) ?></div>
        <?php else: ?>
            <span class="verified-badge-big unverified">⏳ Verification in progress</span>
        <?php endif; ?>

        <ul class="belive-check-list" style="margin-top:16px">
            <?php foreach ($checklist as [$label, $done]): ?>
                <li style="<?= $done ? '' : 'opacity:.45' ?>"><?= e($label) ?><?= $done ? '' : ' — pending' ?></li>
            <?php endforeach; ?>
        </ul>

        <?php if ($scamFlags !== []): ?>
            <div class="belive-alert warning" style="margin-top:14px">
                <strong>Flagged for admin review:</strong>
                <ul style="margin:6px 0 0 18px; font-size:13.5px">
                    <?php foreach ($scamFlags as $flag): ?>
                        <li><?= e($flag['pattern'] ?? '') ?> (<?= e($flag['severity'] ?? '') ?>) — <?= e($flag['detail'] ?? '') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <p class="belive-muted" style="font-size:12px; margin-top:14px">
            How this works: BeLive admin manually reviews the owner's ownership document and confirms the
            listing's coordinates against its address; an AI screen flags common scam patterns for human
            review. This is a trust aid, not a legal guarantee.
        </p>
    <?php endif; ?>
</div>
<?php portal_footer();
