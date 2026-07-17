<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Auth;
use App\Core\Database;
use App\Models\Room;
use App\Properties\PropertyManager;
use App\Properties\RoomPhotoManager;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();

$hasPhoto = static fn (array $files): bool => isset($files['room_photo'])
    && (int) ($files['room_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    try {
        $action = (string) ($_POST['do'] ?? '');
        if ($action === 'add_room') {
            $room = PropertyManager::addRoomForAdmin((int) ($_POST['property_id'] ?? 0), $_POST);
            if ($hasPhoto($_FILES)) {
                try {
                    RoomPhotoManager::addUpload((int) $room['id'], $_FILES['room_photo']);
                    set_flash('success', $room['name'] . ' was added with its room photo.');
                } catch (\PDOException $photoError) {
                    error_log('[admin new room photo database] ' . $photoError->getMessage());
                    set_flash('warning', $room['name'] . ' was added, but its photo could not be saved. Upload it from the room card below.');
                } catch (\RuntimeException $photoError) {
                    set_flash('warning', $room['name'] . ' was added, but its photo was not saved: ' . $photoError->getMessage());
                } catch (\Throwable $photoError) {
                    error_log('[admin new room photo] ' . $photoError->getMessage());
                    set_flash('warning', $room['name'] . ' was added, but its photo could not be saved. Upload it from the room card below.');
                }
            } else {
                set_flash('success', $room['name'] . ' was added to ' . $room['property_name'] . '.');
            }
        } elseif ($action === 'update_room') {
            $room = PropertyManager::updateRoomForAdmin((int) ($_POST['room_id'] ?? 0), $_POST);
            set_flash('success', $room['name'] . ' was updated.');
        } elseif ($action === 'upload_photo') {
            $photo = RoomPhotoManager::addUpload(
                (int) ($_POST['room_id'] ?? 0),
                $_FILES['room_photo'] ?? []
            );
            set_flash('success', 'Room photo added to the gallery as image #' . $photo['id'] . '.');
        } else {
            throw new RuntimeException('Choose a valid room-management action.');
        }
    } catch (\PDOException $e) {
        error_log('[admin rooms database] ' . $e->getMessage());
        set_flash('danger', 'The room change could not be saved. Please check the details and try again.');
    } catch (\InvalidArgumentException|\RuntimeException $e) {
        set_flash('danger', $e->getMessage());
    } catch (\Throwable $e) {
        error_log('[admin rooms] ' . $e->getMessage());
        set_flash('danger', 'The room change could not be saved. Please try again.');
    }

    $returnOwner = trim((string) ($_POST['return_owner'] ?? ''));
    header('Location: /admin/rooms' . ($returnOwner !== '' ? '?owner=' . rawurlencode($returnOwner) : ''));
    exit;
}

$ownerFilter = trim((string) ($_GET['owner'] ?? ''));
$owners = Database::run(
    "SELECT DISTINCT owner_name FROM properties WHERE owner_name <> '' ORDER BY owner_name"
)->fetchAll(PDO::FETCH_COLUMN);
if ($ownerFilter !== '' && !in_array($ownerFilter, $owners, true)) {
    $ownerFilter = '';
}

$properties = Database::run(
    "SELECT * FROM properties WHERE review_status = 'approved' ORDER BY owner_name, name"
)->fetchAll();
$roomSql = "SELECT r.*, p.name AS approved_property_name
            FROM rooms r
            JOIN properties p ON p.id = r.property_id
            WHERE p.review_status = 'approved'";
$roomParams = [];
if ($ownerFilter !== '') {
    $roomSql .= ' AND r.owner_name = ?';
    $roomParams[] = $ownerFilter;
}
$rooms = Database::run($roomSql . ' ORDER BY r.owner_name, p.name, r.room_code, r.name', $roomParams)->fetchAll();

$renderRoomFields = static function (?array $room, array $prices, string $prefix): void {
    $roomType = (string) ($room['room_type'] ?? 'single');
    $status = (string) ($room['status'] ?? 'available');
    ?>
    <div class="belive-field">
        <label for="<?= e($prefix) ?>-code">Room code</label>
        <input id="<?= e($prefix) ?>-code" name="room_code" type="text" required maxlength="20" pattern="[A-Za-z0-9-]{2,20}" value="<?= e($room['room_code'] ?? '') ?>" placeholder="A-12-3">
    </div>
    <div class="belive-field">
        <label for="<?= e($prefix) ?>-name">Room name</label>
        <input id="<?= e($prefix) ?>-name" name="name" type="text" required maxlength="120" value="<?= e($room['name'] ?? '') ?>" placeholder="Middle room">
    </div>
    <div class="belive-field">
        <label for="<?= e($prefix) ?>-type">Room type</label>
        <select id="<?= e($prefix) ?>-type" name="room_type" required>
            <?php foreach (['single' => 'Single', 'middle' => 'Middle', 'master' => 'Master'] as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $roomType === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="belive-field">
        <label for="<?= e($prefix) ?>-status">Status</label>
        <select id="<?= e($prefix) ?>-status" name="status" required>
            <?php foreach (['available' => 'Available', 'reserved' => 'Reserved', 'occupied' => 'Occupied'] as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="belive-field">
        <label for="<?= e($prefix) ?>-monthly">Monthly price (RM)</label>
        <input id="<?= e($prefix) ?>-monthly" name="price_monthly" type="number" min="1" step="0.01" required value="<?= e(isset($prices['monthly']) ? number_format((float) $prices['monthly']['price'], 2, '.', '') : '') ?>">
    </div>
    <div class="belive-field">
        <label for="<?= e($prefix) ?>-six">6-month price (RM)</label>
        <input id="<?= e($prefix) ?>-six" name="price_6_month" type="number" min="1" step="0.01" required value="<?= e(isset($prices['6_month']) ? number_format((float) $prices['6_month']['price'], 2, '.', '') : '') ?>">
    </div>
    <div class="belive-field">
        <label for="<?= e($prefix) ?>-twelve">12-month price (RM)</label>
        <input id="<?= e($prefix) ?>-twelve" name="price_12_month" type="number" min="1" step="0.01" required value="<?= e(isset($prices['12_month']) ? number_format((float) $prices['12_month']['price'], 2, '.', '') : '') ?>">
    </div>
    <div class="belive-field">
        <label for="<?= e($prefix) ?>-points">Referral points</label>
        <input id="<?= e($prefix) ?>-points" name="referral_reward_points" type="number" min="0" max="1000" step="1" required value="<?= e($room['referral_reward_points'] ?? REFERRAL_REWARD_POINTS) ?>">
    </div>
    <div class="belive-field">
        <label for="<?= e($prefix) ?>-available">Available from <span class="belive-muted">(optional)</span></label>
        <input id="<?= e($prefix) ?>-available" name="available_from" type="date" value="<?= e($room['available_from'] ?? '') ?>">
    </div>
    <div class="belive-field admin-room-form-wide">
        <label for="<?= e($prefix) ?>-description">Room description <span class="belive-muted">(optional)</span></label>
        <textarea id="<?= e($prefix) ?>-description" name="description" rows="2" maxlength="2000"><?= e($room['description'] ?? '') ?></textarea>
    </div>
    <?php
};

admin_header('Rooms', 'rooms');
?>
<div class="belive-page-head admin-room-page-head">
    <div>
        <h1>Room management</h1>
        <p class="belive-muted">Manage rooms uploaded by owners, add rooms to approved properties and maintain each room gallery.</p>
    </div>
    <span class="belive-badge orange"><?= count($rooms) ?> room<?= count($rooms) === 1 ? '' : 's' ?></span>
</div>

<div class="admin-room-toolbar">
    <form method="get" action="/admin/rooms" class="admin-room-owner-filter">
        <label for="room-owner-filter">Owner</label>
        <select id="room-owner-filter" name="owner" onchange="this.form.submit()">
            <option value="">All owners</option>
            <?php foreach ($owners as $ownerName): ?>
                <option value="<?= e($ownerName) ?>" <?= $ownerFilter === $ownerName ? 'selected' : '' ?>><?= e($ownerName) ?></option>
            <?php endforeach; ?>
        </select>
        <noscript><button class="belive-btn-ghost" type="submit">Apply filter</button></noscript>
    </form>

    <details class="admin-room-add">
        <summary class="belive-btn-primary">Add room</summary>
        <?php if ($properties === []): ?>
            <p class="belive-alert warning">Approve a property before adding its first room.</p>
        <?php else: ?>
            <form method="post" action="/admin/rooms" enctype="multipart/form-data" class="admin-room-form">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <input type="hidden" name="do" value="add_room">
                <input type="hidden" name="return_owner" value="<?= e($ownerFilter) ?>">
                <div class="belive-field admin-room-form-wide">
                    <label for="admin-add-property">Approved property</label>
                    <select id="admin-add-property" name="property_id" required>
                        <?php foreach ($properties as $property): ?>
                            <option value="<?= (int) $property['id'] ?>"><?= e($property['owner_name'] . ' — ' . $property['name'] . ' (' . $property['location'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php $renderRoomFields(null, [], 'admin-add-room'); ?>
                <div class="belive-field admin-room-form-wide">
                    <label for="admin-add-photo">Room photo <span class="belive-muted">(optional)</span></label>
                    <input type="hidden" name="MAX_FILE_SIZE" value="5242880">
                    <input id="admin-add-photo" name="room_photo" type="file" accept="image/jpeg,image/png,image/webp" aria-describedby="admin-add-photo-hint">
                    <div id="admin-add-photo-hint" class="hint">JPG, PNG or WebP, maximum 5 MB.</div>
                </div>
                <button class="belive-btn-primary admin-room-form-wide" type="submit">Add room to property</button>
            </form>
        <?php endif; ?>
    </details>
</div>

<?php if ($rooms === []): ?>
    <div class="belive-card review-empty-state">
        <h2>No rooms found</h2>
        <p class="belive-muted"><?= $ownerFilter !== '' ? 'This owner has no rooms under approved properties.' : 'Add the first room after approving a property.' ?></p>
    </div>
<?php else: ?>
    <div class="admin-room-list">
        <?php foreach ($rooms as $room): ?>
            <?php $prices = Room::prices((int) $room['id']); $photos = Room::photoUrls((int) $room['id']); ?>
            <article class="belive-card admin-room-card" aria-labelledby="admin-room-<?= (int) $room['id'] ?>">
                <header class="admin-room-card-head">
                    <div>
                        <div class="admin-room-owner"><?= e($room['owner_name']) ?> &middot; <?= e($room['approved_property_name']) ?></div>
                        <h2 id="admin-room-<?= (int) $room['id'] ?>"><?= e($room['name']) ?></h2>
                        <p><?= e($room['room_code'] ?: 'No room code') ?> &middot; <?= e(ucfirst($room['room_type'])) ?> room &middot; <?= e($room['location']) ?></p>
                    </div>
                    <div class="admin-room-badges">
                        <span class="belive-badge <?= $room['status'] === 'available' ? '' : 'orange' ?>"><?= e(ucfirst($room['status'])) ?></span>
                        <span class="belive-badge muted"><?= count($photos) ?> photo<?= count($photos) === 1 ? '' : 's' ?></span>
                    </div>
                </header>

                <?php if ($photos !== []): ?>
                    <div class="admin-room-gallery" aria-label="<?= e($room['name']) ?> photos">
                        <?php foreach ($photos as $index => $photo): ?>
                            <img src="<?= e($photo) ?>" alt="<?= e($room['name']) ?> room photo <?= $index + 1 ?>" loading="lazy" width="180" height="120">
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="admin-room-no-photo">No room photos uploaded yet.</p>
                <?php endif; ?>

                <form method="post" action="/admin/rooms" enctype="multipart/form-data" class="admin-room-photo-form">
                    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                    <input type="hidden" name="do" value="upload_photo">
                    <input type="hidden" name="room_id" value="<?= (int) $room['id'] ?>">
                    <input type="hidden" name="return_owner" value="<?= e($ownerFilter) ?>">
                    <input type="hidden" name="MAX_FILE_SIZE" value="5242880">
                    <div class="belive-field">
                        <label for="room-photo-<?= (int) $room['id'] ?>">Add room photo</label>
                        <input id="room-photo-<?= (int) $room['id'] ?>" name="room_photo" type="file" required accept="image/jpeg,image/png,image/webp" aria-describedby="room-photo-<?= (int) $room['id'] ?>-hint">
                        <div id="room-photo-<?= (int) $room['id'] ?>-hint" class="hint">JPG, PNG or WebP, maximum 5 MB.</div>
                    </div>
                    <button class="belive-btn-secondary" type="submit">Upload photo</button>
                </form>

                <details class="admin-room-edit">
                    <summary>Edit room details</summary>
                    <form method="post" action="/admin/rooms" class="admin-room-form">
                        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                        <input type="hidden" name="do" value="update_room">
                        <input type="hidden" name="room_id" value="<?= (int) $room['id'] ?>">
                        <input type="hidden" name="return_owner" value="<?= e($ownerFilter) ?>">
                        <?php $renderRoomFields($room, $prices, 'admin-edit-room-' . (int) $room['id']); ?>
                        <button class="belive-btn-primary admin-room-form-wide" type="submit">Save room changes</button>
                    </form>
                </details>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php admin_footer();
