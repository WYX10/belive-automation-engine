<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Auth;
use App\Core\Database;
use App\Models\Property;
use App\Models\PropertyUnit;
use App\Models\Room;
use App\Properties\PropertyManager;
use App\Properties\RoomPhotoEnhancer;
use App\Properties\RoomPhotoManager;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();

$hasPhoto = static fn (array $files): bool => isset($files['room_photo'])
    && (int) ($files['room_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

/**
 * Link back into the drill-down. Every level is a plain URL, so a browser
 * back button, a bookmark and a post-save redirect all land in the same place.
 */
$levelUrl = static function (array $params): string {
    $query = array_filter([
        'owner' => (string) ($params['owner'] ?? ''),
        'location' => (string) ($params['location'] ?? ''),
        'property' => ($params['property'] ?? 0) > 0 ? (string) (int) $params['property'] : '',
        'unit' => ($params['unit'] ?? 0) > 0 ? (string) (int) $params['unit'] : '',
    ], static fn (string $value): bool => $value !== '');

    return '/admin/rooms' . ($query === [] ? '' : '?' . http_build_query($query));
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    try {
        $action = (string) ($_POST['do'] ?? '');
        if ($action === 'add_house') {
            $house = PropertyManager::addUnitForAdmin((int) ($_POST['property_id'] ?? 0), $_POST);
            set_flash('success', $house['name'] . ' was added. Add its rooms next.');
        } elseif ($action === 'update_house') {
            $house = PropertyManager::updateUnitForAdmin((int) ($_POST['unit_id'] ?? 0), $_POST);
            set_flash('success', $house['name'] . ' was updated.');
        } elseif ($action === 'add_room') {
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
        } elseif ($action === 'enhance_photo') {
            // A dark, yellow, crooked phone shot of a good room. The correction
            // is applied to the photograph only — see RoomPhotoEnhancer.
            set_time_limit(120);
            $result = RoomPhotoEnhancer::enhance(
                (int) ($_POST['image_id'] ?? 0),
                trim((string) ($_POST['enhance_brief'] ?? ''))
            );
            set_flash(
                $result['grounded'] ? 'success' : 'warning',
                sprintf('Photo #%d touched up by %s — %s Revert it if the room no longer looks like itself.',
                    $result['id'],
                    $result['model'],
                    rtrim($result['verdict'], '.') . '.'
                )
            );
        } elseif ($action === 'revert_photo') {
            $result = RoomPhotoEnhancer::revert((int) ($_POST['image_id'] ?? 0));
            set_flash('success', 'Photo #' . $result['id'] . ' is back to the owner\'s original upload.');
        } else {
            throw new RuntimeException('Choose a valid room-management action.');
        }
    } catch (\PDOException $e) {
        error_log('[admin rooms database] ' . $e->getMessage());
        set_flash('danger', 'The change could not be saved. Please check the details and try again.');
    } catch (\InvalidArgumentException|\RuntimeException $e) {
        set_flash('danger', $e->getMessage());
    } catch (\Throwable $e) {
        error_log('[admin rooms] ' . $e->getMessage());
        set_flash('danger', 'The change could not be saved. Please try again.');
    }

    header('Location: ' . $levelUrl([
        'owner' => trim((string) ($_POST['return_owner'] ?? '')),
        'location' => trim((string) ($_POST['return_location'] ?? '')),
        'property' => (int) ($_POST['return_property'] ?? 0),
        'unit' => (int) ($_POST['return_unit'] ?? 0),
    ]));
    exit;
}

$ownerFilter = trim((string) ($_GET['owner'] ?? ''));
$owners = Database::run(
    "SELECT DISTINCT owner_name FROM properties WHERE owner_name <> '' ORDER BY owner_name"
)->fetchAll(PDO::FETCH_COLUMN);
if ($ownerFilter !== '' && !in_array($ownerFilter, $owners, true)) {
    $ownerFilter = '';
}

// Location is the question owners actually ask first ("what do we have in
// Setapak?"), so it filters the property list alongside the owner.
$locationFilter = trim((string) ($_GET['location'] ?? ''));
$locations = Database::run(
    "SELECT DISTINCT location FROM properties
     WHERE review_status = 'approved' AND location <> '' ORDER BY location"
)->fetchAll(PDO::FETCH_COLUMN);
if ($locationFilter !== '' && !in_array($locationFilter, $locations, true)) {
    $locationFilter = '';
}

// Which level of the hierarchy is open. A stale or hand-typed id drops back to
// the property list with an explanation rather than a blank page.
$house = null;
$property = null;
$requestedUnitId = (int) ($_GET['unit'] ?? 0);
$requestedPropertyId = (int) ($_GET['property'] ?? 0);

if ($requestedUnitId > 0) {
    $house = PropertyUnit::find($requestedUnitId);
    if ($house === null) {
        set_flash('warning', 'That house no longer exists.');
    } else {
        $requestedPropertyId = (int) $house['property_id'];
    }
}
if ($requestedPropertyId > 0) {
    $property = Database::run(
        "SELECT * FROM properties WHERE id = ? AND review_status = 'approved'",
        [$requestedPropertyId]
    )->fetch() ?: null;
    if ($property === null) {
        set_flash('warning', 'That property is not available — it may be pending review or rejected.');
        $house = null;
    }
}

$level = $property === null ? 'properties' : ($house === null ? 'houses' : 'rooms');

$renderRoomFields = static function (?array $room, array $prices, string $prefix, array $houses = [], int $selectedUnitId = 0): void {
    $roomType = (string) ($room['room_type'] ?? 'single');
    $status = (string) ($room['status'] ?? 'available');
    ?>
    <?php if ($houses !== []): ?>
        <div class="belive-field">
            <label for="<?= e($prefix) ?>-house">House</label>
            <select id="<?= e($prefix) ?>-house" name="unit_id" required>
                <?php foreach ($houses as $option): ?>
                    <option value="<?= (int) $option['id'] ?>" <?= (int) $option['id'] === $selectedUnitId ? 'selected' : '' ?>><?= e($option['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
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

/** Counted the same way everywhere: a stat is only useful if it means one thing. */
$renderStats = static function (array $stats): void {
    ?>
    <dl class="admin-hier-stats">
        <?php foreach ($stats as $label => $value): ?>
            <div>
                <dt><?= e($label) ?></dt>
                <dd><?= e((string) $value) ?></dd>
            </div>
        <?php endforeach; ?>
    </dl>
    <?php
};

admin_header('Rooms', 'rooms');
?>
<div class="belive-page-head admin-room-page-head">
    <div>
        <h1>Room management</h1>
        <p class="belive-muted">
            <?php if ($level === 'properties'): ?>
                Pick a property to see the houses inside it, then a house to see its rooms and who is renting them.
            <?php elseif ($level === 'houses'): ?>
                Houses inside <?= e($property['name']) ?>. Open one to manage its rooms.
            <?php else: ?>
                Rooms inside <?= e($house['name']) ?>, and the tenants renting them.
            <?php endif; ?>
        </p>
    </div>
</div>

<?php if ($level !== 'properties'): ?>
    <nav class="admin-crumbs" aria-label="Breadcrumb">
        <a href="<?= e($levelUrl(['owner' => $ownerFilter, 'location' => $locationFilter])) ?>">All properties</a>
        <span aria-hidden="true">›</span>
        <?php if ($level === 'houses'): ?>
            <span aria-current="page"><?= e($property['name']) ?></span>
        <?php else: ?>
            <a href="<?= e($levelUrl(['owner' => $ownerFilter, 'location' => $locationFilter, 'property' => (int) $property['id']])) ?>"><?= e($property['name']) ?></a>
            <span aria-hidden="true">›</span>
            <span aria-current="page"><?= e($house['name']) ?></span>
        <?php endif; ?>
    </nav>
<?php endif; ?>

<?php
// ---------------------------------------------------------------- properties
if ($level === 'properties'):
    $propertySql = "SELECT * FROM properties WHERE review_status = 'approved'";
    $propertyParams = [];
    if ($ownerFilter !== '') {
        $propertySql .= ' AND owner_name = ?';
        $propertyParams[] = $ownerFilter;
    }
    if ($locationFilter !== '') {
        $propertySql .= ' AND location = ?';
        $propertyParams[] = $locationFilter;
    }
    $properties = Database::run($propertySql . ' ORDER BY owner_name, name', $propertyParams)->fetchAll();
    $counts = Property::portfolioCounts(array_map(static fn (array $row): int => (int) $row['id'], $properties));
    ?>
    <div class="admin-room-toolbar">
        <form method="get" action="/admin/rooms" class="admin-room-owner-filter">
            <label for="room-owner-filter">Owner</label>
            <select id="room-owner-filter" name="owner" onchange="this.form.submit()">
                <option value="">All owners</option>
                <?php foreach ($owners as $ownerName): ?>
                    <option value="<?= e($ownerName) ?>" <?= $ownerFilter === $ownerName ? 'selected' : '' ?>><?= e($ownerName) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="room-location-filter">Location</label>
            <select id="room-location-filter" name="location" onchange="this.form.submit()">
                <option value="">All locations</option>
                <?php foreach ($locations as $locationName): ?>
                    <option value="<?= e($locationName) ?>" <?= $locationFilter === $locationName ? 'selected' : '' ?>><?= e($locationName) ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button class="belive-btn-ghost" type="submit">Apply filters</button></noscript>
        </form>
        <span class="belive-badge orange"><?= count($properties) ?> propert<?= count($properties) === 1 ? 'y' : 'ies' ?></span>
    </div>

    <?php if ($properties === []): ?>
        <div class="belive-card review-empty-state">
            <h2>No approved properties</h2>
            <p class="belive-muted">
                <?php if ($ownerFilter !== '' && $locationFilter !== ''): ?>
                    <?= e($ownerFilter) ?> has no approved properties in <?= e($locationFilter) ?>.
                <?php elseif ($ownerFilter !== ''): ?>
                    This owner has no approved properties yet.
                <?php elseif ($locationFilter !== ''): ?>
                    No approved properties in <?= e($locationFilter) ?> yet.
                <?php else: ?>
                    Approve a property in Property reviews before adding houses and rooms.
                <?php endif; ?>
            </p>
        </div>
    <?php else: ?>
        <div class="admin-hier-list">
            <?php foreach ($properties as $row): ?>
                <?php $stat = $counts[(int) $row['id']] ?? ['houses' => 0, 'rooms' => 0, 'tenants' => 0, 'available' => 0]; ?>
                <a class="belive-card admin-hier-card" href="<?= e($levelUrl(['owner' => $ownerFilter, 'location' => $locationFilter, 'property' => (int) $row['id']])) ?>">
                    <div class="admin-hier-card-head">
                        <div>
                            <div class="admin-room-owner"><?= e($row['owner_name']) ?></div>
                            <h2><?= e($row['name']) ?></h2>
                            <p><?= e($row['location']) ?> &middot; <?= e($row['address']) ?></p>
                        </div>
                        <span class="admin-hier-go" aria-hidden="true">›</span>
                    </div>
                    <?php $renderStats([
                        'Houses' => $stat['houses'],
                        'Rooms' => $stat['rooms'],
                        'Tenants' => $stat['tenants'],
                        'Available' => $stat['available'],
                    ]); ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php
// -------------------------------------------------------------------- houses
elseif ($level === 'houses'):
    $houses = PropertyUnit::forProperty((int) $property['id']);
    $counts = PropertyUnit::countsForProperty((int) $property['id']);
    ?>
    <div class="admin-room-toolbar">
        <div class="admin-hier-context">
            <div class="admin-room-owner"><?= e($property['owner_name']) ?></div>
            <strong><?= e($property['name']) ?></strong>
            <span class="belive-muted"><?= e($property['location']) ?> &middot; <?= e($property['address']) ?></span>
        </div>
        <details class="admin-room-add">
            <summary class="belive-btn-primary">Add house</summary>
            <form method="post" action="/admin/rooms" class="admin-room-form">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <input type="hidden" name="do" value="add_house">
                <input type="hidden" name="property_id" value="<?= (int) $property['id'] ?>">
                <input type="hidden" name="return_owner" value="<?= e($ownerFilter) ?>">
                <input type="hidden" name="return_location" value="<?= e($locationFilter) ?>">
                <input type="hidden" name="return_property" value="<?= (int) $property['id'] ?>">
                <div class="belive-field admin-room-form-wide">
                    <label for="admin-add-house-name">House name</label>
                    <input id="admin-add-house-name" name="name" type="text" required maxlength="120" placeholder="Unit A-12-3">
                </div>
                <div class="belive-field admin-room-form-wide">
                    <label for="admin-add-house-notes">Notes <span class="belive-muted">(optional)</span></label>
                    <input id="admin-add-house-notes" name="notes" type="text" maxlength="255" placeholder="3 bedrooms, 2 bathrooms, level 12">
                </div>
                <button class="belive-btn-primary admin-room-form-wide" type="submit">Add house to this property</button>
            </form>
        </details>
    </div>

    <?php if ($houses === []): ?>
        <div class="belive-card review-empty-state">
            <h2>No houses yet</h2>
            <p class="belive-muted">Add the first house in <?= e($property['name']) ?> — rooms live inside a house, not directly under the property.</p>
        </div>
    <?php else: ?>
        <div class="admin-hier-list">
            <?php foreach ($houses as $row): ?>
                <?php $stat = $counts[(int) $row['id']] ?? ['rooms' => 0, 'tenants' => 0, 'available' => 0]; ?>
                <article class="belive-card admin-hier-card is-static">
                    <div class="admin-hier-card-head">
                        <div>
                            <div class="admin-room-owner">House</div>
                            <h2><a href="<?= e($levelUrl(['owner' => $ownerFilter, 'location' => $locationFilter, 'property' => (int) $property['id'], 'unit' => (int) $row['id']])) ?>"><?= e($row['name']) ?></a></h2>
                            <p><?= e($row['notes'] ?: 'No notes for this house.') ?></p>
                        </div>
                        <a class="belive-btn-secondary" href="<?= e($levelUrl(['owner' => $ownerFilter, 'location' => $locationFilter, 'property' => (int) $property['id'], 'unit' => (int) $row['id']])) ?>">View rooms</a>
                    </div>
                    <?php $renderStats([
                        'Rooms' => $stat['rooms'],
                        'Tenants' => $stat['tenants'],
                        'Available' => $stat['available'],
                    ]); ?>
                    <details class="admin-room-edit">
                        <summary>Edit house details</summary>
                        <form method="post" action="/admin/rooms" class="admin-room-form">
                            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                            <input type="hidden" name="do" value="update_house">
                            <input type="hidden" name="unit_id" value="<?= (int) $row['id'] ?>">
                            <input type="hidden" name="return_owner" value="<?= e($ownerFilter) ?>">
                            <input type="hidden" name="return_location" value="<?= e($locationFilter) ?>">
                            <input type="hidden" name="return_property" value="<?= (int) $property['id'] ?>">
                            <div class="belive-field admin-room-form-wide">
                                <label for="house-name-<?= (int) $row['id'] ?>">House name</label>
                                <input id="house-name-<?= (int) $row['id'] ?>" name="name" type="text" required maxlength="120" value="<?= e($row['name']) ?>">
                            </div>
                            <div class="belive-field admin-room-form-wide">
                                <label for="house-notes-<?= (int) $row['id'] ?>">Notes <span class="belive-muted">(optional)</span></label>
                                <input id="house-notes-<?= (int) $row['id'] ?>" name="notes" type="text" maxlength="255" value="<?= e($row['notes'] ?? '') ?>">
                            </div>
                            <button class="belive-btn-primary admin-room-form-wide" type="submit">Save house changes</button>
                        </form>
                    </details>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php
// --------------------------------------------------------------------- rooms
else:
    $houses = PropertyUnit::forProperty((int) $property['id']);
    $rooms = Database::run(
        'SELECT * FROM rooms WHERE unit_id = ? ORDER BY room_code, name',
        [(int) $house['id']]
    )->fetchAll();
    ?>
    <div class="admin-room-toolbar">
        <div class="admin-hier-context">
            <div class="admin-room-owner"><?= e($property['owner_name']) ?> &middot; <?= e($property['name']) ?></div>
            <strong><?= e($house['name']) ?></strong>
            <span class="belive-muted"><?= e($house['notes'] ?: $property['address']) ?></span>
        </div>
        <details class="admin-room-add">
            <summary class="belive-btn-primary">Add room</summary>
            <form method="post" action="/admin/rooms" enctype="multipart/form-data" class="admin-room-form">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <input type="hidden" name="do" value="add_room">
                <input type="hidden" name="property_id" value="<?= (int) $property['id'] ?>">
                <input type="hidden" name="return_owner" value="<?= e($ownerFilter) ?>">
                <input type="hidden" name="return_location" value="<?= e($locationFilter) ?>">
                <input type="hidden" name="return_property" value="<?= (int) $property['id'] ?>">
                <input type="hidden" name="return_unit" value="<?= (int) $house['id'] ?>">
                <?php $renderRoomFields(null, [], 'admin-add-room', $houses, (int) $house['id']); ?>
                <div class="belive-field admin-room-form-wide">
                    <label for="admin-add-photo">Room photo <span class="belive-muted">(optional)</span></label>
                    <input type="hidden" name="MAX_FILE_SIZE" value="5242880">
                    <input id="admin-add-photo" name="room_photo" type="file" accept="image/jpeg,image/png,image/webp" aria-describedby="admin-add-photo-hint">
                    <div id="admin-add-photo-hint" class="hint">JPG, PNG or WebP, maximum 5 MB.</div>
                </div>
                <button class="belive-btn-primary admin-room-form-wide" type="submit">Add room to this house</button>
            </form>
        </details>
    </div>

    <?php if ($rooms === []): ?>
        <div class="belive-card review-empty-state">
            <h2>No rooms in this house</h2>
            <p class="belive-muted">Add the first room in <?= e($house['name']) ?>.</p>
        </div>
    <?php else: ?>
        <div class="admin-room-list">
            <?php foreach ($rooms as $room): ?>
                <?php
                $prices = Room::prices((int) $room['id']);
                $photos = Room::photoRows((int) $room['id']);
                $tenants = Room::tenants((int) $room['id']);
                ?>
                <article class="belive-card admin-room-card" aria-labelledby="admin-room-<?= (int) $room['id'] ?>">
                    <header class="admin-room-card-head">
                        <div>
                            <div class="admin-room-owner"><?= e($room['owner_name']) ?> &middot; <?= e($property['name']) ?> &middot; <?= e($house['name']) ?></div>
                            <h2 id="admin-room-<?= (int) $room['id'] ?>"><?= e($room['name']) ?></h2>
                            <p><?= e($room['room_code'] ?: 'No room code') ?> &middot; <?= e(ucfirst($room['room_type'])) ?> room &middot; <?= e($room['location']) ?></p>
                        </div>
                        <div class="admin-room-badges">
                            <span class="belive-badge <?= $room['status'] === 'available' ? '' : 'orange' ?>"><?= e(ucfirst($room['status'])) ?></span>
                            <span class="belive-badge <?= $tenants === [] ? 'muted' : '' ?>"><?= count($tenants) ?> tenant<?= count($tenants) === 1 ? '' : 's' ?></span>
                            <span class="belive-badge muted"><?= count($photos) ?> photo<?= count($photos) === 1 ? '' : 's' ?></span>
                        </div>
                    </header>

                    <div class="admin-room-tenants">
                        <h3>Renting this room</h3>
                        <?php if ($tenants === []): ?>
                            <p class="belive-muted">Nobody is renting this room — no confirmed or completed booking yet.</p>
                        <?php else: ?>
                            <ul>
                                <?php foreach ($tenants as $tenant): ?>
                                    <li>
                                        <strong><?= e($tenant['name'] !== '' ? $tenant['name'] : 'Unnamed tenant') ?></strong>
                                        <span class="belive-muted"><?= e($tenant['wa_phone']) ?><?= $tenant['booked_at'] !== null ? ' &middot; booked ' . e(date('j M Y', strtotime($tenant['booked_at']))) : '' ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>

                    <?php if ($photos !== []): ?>
                        <div class="admin-room-gallery" aria-label="<?= e($room['name']) ?> photos">
                            <?php foreach ($photos as $index => $photo): ?>
                                <?php $touchedUp = ($photo['original_path'] ?? null) !== null; ?>
                                <figure class="admin-room-shot">
                                    <img src="<?= e($photo['image_path']) ?>" alt="<?= e($room['name']) ?> room photo <?= $index + 1 ?>" loading="lazy" width="180" height="120">
                                    <figcaption>
                                        <?php if ($touchedUp): ?>
                                            <span class="belive-badge">AI touch-up</span>
                                            <p class="admin-shot-note"><?= e((string) $photo['enhance_note']) ?></p>
                                            <p class="admin-shot-meta">Lighting and colour only, by <?= e((string) $photo['enhanced_by_model']) ?>. The room itself is unchanged.</p>
                                        <?php else: ?>
                                            <span class="belive-badge muted">Owner's original</span>
                                        <?php endif; ?>
                                    </figcaption>

                                    <div class="admin-shot-actions">
                                        <details>
                                            <summary><?= $touchedUp ? 'Redo the touch-up' : 'Improve with AI' ?></summary>
                                            <form method="post" action="/admin/rooms">
                                                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                                                <input type="hidden" name="do" value="enhance_photo">
                                                <input type="hidden" name="image_id" value="<?= (int) $photo['id'] ?>">
                                                <input type="hidden" name="return_owner" value="<?= e($ownerFilter) ?>">
                                                <input type="hidden" name="return_location" value="<?= e($locationFilter) ?>">
                                                <input type="hidden" name="return_property" value="<?= (int) $property['id'] ?>">
                                                <input type="hidden" name="return_unit" value="<?= (int) $house['id'] ?>">
                                                <div class="belive-field">
                                                    <label for="enhance-brief-<?= (int) $photo['id'] ?>">What's wrong with it? <span class="belive-muted">(optional)</span></label>
                                                    <input id="enhance-brief-<?= (int) $photo['id'] ?>" name="enhance_brief" type="text" maxlength="200" placeholder="too dark, yellow light, tilted…" aria-describedby="enhance-hint-<?= (int) $photo['id'] ?>">
                                                    <div id="enhance-hint-<?= (int) $photo['id'] ?>" class="hint">The AI fixes the photo — exposure, colour, tilt, sharpness. It never adds furniture or renovation the room doesn't have.</div>
                                                </div>
                                                <button class="belive-btn-secondary" type="submit">Run AI touch-up</button>
                                            </form>
                                        </details>
                                        <?php if ($touchedUp): ?>
                                            <form method="post" action="/admin/rooms">
                                                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                                                <input type="hidden" name="do" value="revert_photo">
                                                <input type="hidden" name="image_id" value="<?= (int) $photo['id'] ?>">
                                                <input type="hidden" name="return_owner" value="<?= e($ownerFilter) ?>">
                                                <input type="hidden" name="return_location" value="<?= e($locationFilter) ?>">
                                                <input type="hidden" name="return_property" value="<?= (int) $property['id'] ?>">
                                                <input type="hidden" name="return_unit" value="<?= (int) $house['id'] ?>">
                                                <button class="belive-btn-ghost" type="submit">Revert to the original</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </figure>
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
                        <input type="hidden" name="return_location" value="<?= e($locationFilter) ?>">
                        <input type="hidden" name="return_property" value="<?= (int) $property['id'] ?>">
                        <input type="hidden" name="return_unit" value="<?= (int) $house['id'] ?>">
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
                            <input type="hidden" name="return_location" value="<?= e($locationFilter) ?>">
                            <input type="hidden" name="return_property" value="<?= (int) $property['id'] ?>">
                            <input type="hidden" name="return_unit" value="<?= (int) $house['id'] ?>">
                            <?php $renderRoomFields($room, $prices, 'admin-edit-room-' . (int) $room['id'], $houses, (int) $room['unit_id']); ?>
                            <button class="belive-btn-primary admin-room-form-wide" type="submit">Save room changes</button>
                        </form>
                    </details>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php admin_footer();
