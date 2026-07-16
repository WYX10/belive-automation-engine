<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Catalog\PricingCalculator;
use App\Catalog\RoomRepository;
use App\Models\Room;

require __DIR__ . '/_site_layout.php';

$room = RoomRepository::findWithDetails((int) ($_GET['id'] ?? 0));
if ($room === null) {
    http_response_code(404);
    site_header('Room not found');
    echo '<div class="belive-card" style="margin-top:40px; text-align:center"><p>That room is no longer listed.</p><p style="margin-top:10px"><a class="belive-btn-secondary" href="/rooms">Browse all rooms</a></p></div>';
    site_footer();
    return;
}

$roomId = (int) $room['id'];
$saving = PricingCalculator::savingVsFlexible($roomId, '12_month');
$enquired = ($_GET['enquired'] ?? '') === '1';

site_header($room['property_name'] ?: $room['name'], 'rooms');
?>
<div style="padding-top:30px">
    <a href="/rooms" style="font-size:13.5px">← All rooms</a>
    <div style="display:flex; justify-content:space-between; align-items:baseline; gap:12px; flex-wrap:wrap; margin-top:8px">
        <h1 style="font-size:26px"><?= e($room['property_name'] ?: $room['name']) ?> <span class="belive-muted" style="font-size:15px; font-weight:400">· <?= e($room['room_code']) ?></span></h1>
        <div style="display:flex; gap:8px">
            <span class="belive-badge orange" style="font-size:13px">RM 0 deposit</span>
            <span class="belive-badge <?= $room['status'] === 'available' ? '' : 'muted' ?>" style="font-size:13px"><?= e($room['status']) ?></span>
        </div>
    </div>
    <p class="belive-muted" style="margin:4px 0 18px">📍 <?= e($room['location']) ?> · <?= e(ucfirst($room['room_type'])) ?> room<?= $room['available_from'] ? ' · available from ' . e(date('j M Y', strtotime($room['available_from']))) : '' ?></p>
</div>

<?php if ($enquired): ?>
    <div class="belive-alert success">✓ Enquiry sent! Check your WhatsApp — Eve already has this room's details and is ready to help.</div>
<?php elseif (($_GET['error'] ?? '') !== ''): ?>
    <div class="belive-alert danger"><?= e($_GET['error']) ?></div>
<?php endif; ?>

<?php $images = $room['images']; ?>
<?php if ($images !== []): ?>
    <div class="detail-gallery">
        <div class="main"><img src="<?= e($images[0]) ?>" alt="<?= e($room['property_name']) ?> — main photo"></div>
        <?php foreach (array_slice($images, 1, 2) as $i => $img): ?>
            <div class="side"><img src="<?= e($img) ?>" alt="<?= e($room['property_name']) ?> — photo <?= $i + 2 ?>"></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($room['videos'] !== []): ?>
    <div class="belive-card" style="margin-top:16px">
        <div class="belive-card-title">🎥 Video tour</div>
        <video controls muted playsinline preload="metadata"
               poster="<?= e($images[0] ?? '') ?>"
               style="width:100%; max-height:440px; border-radius:12px; background:var(--belive-ink)">
            <source src="<?= e($room['videos'][0]) ?>" type="video/mp4">
        </video>
    </div>
<?php endif; ?>

<div class="belive-row" style="margin-top:22px">
    <div class="belive-col" style="flex:1.4">
        <div class="belive-card">
            <div class="belive-card-title">💰 Pricing by stay length</div>
            <table class="tenure-table">
                <?php foreach (Room::TENURES as $tenure): ?>
                    <?php if (!isset($room['prices'][$tenure])) continue; ?>
                    <?php $best = $room['prices'][$tenure]['is_best_value']; ?>
                    <tr class="<?= $best ? 'best' : '' ?>">
                        <td>
                            <?= e(Room::TENURE_LABELS[$tenure]) ?>
                            <?php if ($best): ?><span class="belive-badge" style="margin-left:6px">★ Best value</span><?php endif; ?>
                        </td>
                        <td class="price">RM <?= e(number_format($room['prices'][$tenure]['price'])) ?><span style="font-size:12px; font-weight:400; color:var(--belive-muted)">/mo</span></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <?php if ($saving !== null): ?>
                <div class="belive-alert success" style="margin:14px 0 0; font-size:13.5px">
                    Commit to 12 months and save <strong>RM <?= e(number_format($saving['monthly_saving'])) ?>/month</strong>
                    (RM <?= e(number_format($saving['yearly_saving'])) ?> a year, <?= e((string) $saving['pct']) ?>% below the flexible rate) — plus RM 0 deposit either way.
                </div>
            <?php endif; ?>
        </div>

        <?php if ($room['description']): ?>
            <div class="belive-card" style="margin-top:16px">
                <div class="belive-card-title">🏠 About this room</div>
                <p style="font-size:14.5px"><?= e($room['description']) ?></p>
            </div>
        <?php endif; ?>

        <div class="belive-card" style="margin-top:16px">
            <div class="belive-card-title">✨ What's included</div>
            <ul class="belive-check-list" style="columns:2; column-gap:24px">
                <?php foreach ($room['amenities'] as $amenity): ?>
                    <li style="break-inside:avoid"><?= e($amenity) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <div class="belive-col">
        <div class="belive-card" id="enquire">
            <div class="belive-card-title">💬 Enquire about this room</div>
            <p class="belive-muted" style="font-size:13px; margin-bottom:14px">Eve, our AI assistant, replies on WhatsApp within seconds — with this exact room's details already in hand.</p>
            <form method="post" action="/enquire">
                <input type="hidden" name="room_id" value="<?= $roomId ?>">
                <div class="belive-field">
                    <label for="name">Your name</label>
                    <input id="name" name="name" type="text" required>
                </div>
                <div class="belive-field">
                    <label for="wa_phone">WhatsApp number</label>
                    <input id="wa_phone" name="wa_phone" type="tel" required placeholder="60123456789">
                </div>
                <div class="belive-field">
                    <label for="tenure">Stay length you're considering</label>
                    <select id="tenure" name="tenure">
                        <?php foreach (Room::TENURE_LABELS as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $value === '12_month' ? 'selected' : '' ?>>
                                <?= e($label) ?><?= isset($room['prices'][$value]) ? ' — RM ' . number_format($room['prices'][$value]['price']) . '/mo' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="belive-field">
                    <label for="message">Anything else? <span class="belive-muted">(optional)</span></label>
                    <textarea id="message" name="message" rows="2" placeholder="e.g. Moving in August, is it near the LRT?"></textarea>
                </div>
                <button type="submit" class="belive-btn-primary" style="width:100%; justify-content:center">Enquire on WhatsApp</button>
            </form>
            <p class="belive-muted" style="font-size:11.5px; margin-top:10px">Or message us directly: <a href="<?= e(eve_whatsapp_link()) ?>" target="_blank" rel="noopener">Chat with us</a></p>
        </div>
    </div>
</div>
<?php site_footer();
