<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Catalog\RoomRepository;
use App\Models\Room;

require __DIR__ . '/_site_layout.php';

$filters = [
    'location'  => trim($_GET['location'] ?? '') ?: null,
    'room_type' => $_GET['room_type'] ?? null,
    'tenure'    => $_GET['tenure'] ?? '12_month',
    'max_price' => (int) ($_GET['max_price'] ?? 0) ?: null,
];

$rooms = RoomRepository::filter($filters);
$locations = RoomRepository::locations();

site_header('Find a room', 'rooms');
?>
<div style="padding-top:34px">
    <h1 style="font-size:28px">Find a room</h1>
    <p class="belive-muted" style="margin-top:4px">All prices per stay length · every room RM 0 deposit · tenure 6-month, 12-month and flexible monthly.</p>
</div>

<form class="filter-bar" method="get" action="/rooms">
    <div class="belive-field">
        <label>Location</label>
        <select name="location">
            <option value="">Anywhere</option>
            <?php foreach ($locations as $loc): ?>
                <option value="<?= e($loc['location']) ?>" <?= $filters['location'] === $loc['location'] ? 'selected' : '' ?>><?= e($loc['location']) ?> (<?= (int) $loc['n'] ?>)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="belive-field">
        <label>Room type</label>
        <select name="room_type">
            <option value="">Any</option>
            <?php foreach (['single' => 'Single Room', 'middle' => 'Middle Room', 'master' => 'Master Room'] as $value => $label): ?>
                <option value="<?= $value ?>" <?= $filters['room_type'] === $value ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="belive-field">
        <label>Stay length</label>
        <select name="tenure">
            <?php foreach (Room::TENURE_LABELS as $value => $label): ?>
                <option value="<?= $value ?>" <?= $filters['tenure'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="belive-field">
        <label>Max budget (RM/mo)</label>
        <input type="number" name="max_price" min="0" step="50" placeholder="e.g. 700" value="<?= $filters['max_price'] ? (int) $filters['max_price'] : '' ?>">
    </div>
    <button type="submit" class="belive-btn-primary">Search</button>
</form>

<?php if ($rooms === []): ?>
    <div class="belive-card" style="text-align:center; padding:40px">
        <p style="font-weight:600">No rooms match those filters.</p>
        <p class="belive-muted" style="font-size:14px; margin-top:6px">Try widening your budget or location — or
            <a href="<?= e(eve_whatsapp_link()) ?>" target="_blank" rel="noopener">ask Eve on WhatsApp</a>; she knows the full inventory.</p>
    </div>
<?php else: ?>
    <p class="belive-muted" style="font-size:13.5px; margin-bottom:14px"><?= count($rooms) ?> room<?= count($rooms) === 1 ? '' : 's' ?> · budget filter applied at the <?= e(Room::TENURE_LABELS[$filters['tenure']]) ?> rate</p>
    <div class="room-grid">
        <?php foreach ($rooms as $room) { room_card($room); } ?>
    </div>
<?php endif; ?>
<?php site_footer();
