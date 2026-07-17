<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Auth;
use App\Core\Database;
use App\Models\Property;
use App\Models\Room;
use App\Properties\PropertyManager;

require dirname(__DIR__) . '/_portal_layout.php';
$owner = require_owner();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    try {
        $action = $_POST['do'] ?? '';
        if ($action === 'add_property') {
            $property = PropertyManager::addProperty($owner, $_POST);
            set_flash('success', $property['name'] . ' was added. You can add rooms below.');
        } elseif ($action === 'add_room') {
            $room = PropertyManager::addRoom($owner, $_POST);
            set_flash('success', $room['name'] . ' was added with its prices and referral points.');
        } elseif ($action === 'update_referral_points') {
            $room = PropertyManager::updateReferralPoints(
                $owner,
                (int) ($_POST['room_id'] ?? 0),
                $_POST['referral_reward_points'] ?? null
            );
            set_flash('success', $room['name'] . ' now awards ' . $room['referral_reward_points'] . ' referral points.');
        } else {
            throw new RuntimeException('Choose a valid property action.');
        }
    } catch (\PDOException $e) {
        error_log('[owner properties database] ' . $e->getMessage());
        set_flash('danger', 'The property change could not be saved. Please check the details and try again.');
    } catch (\InvalidArgumentException|\RuntimeException $e) {
        set_flash('danger', $e->getMessage());
    } catch (\Throwable $e) {
        error_log('[owner properties] ' . $e->getMessage());
        set_flash('danger', 'The property change could not be saved. Please try again.');
    }
    header('Location: /owner/properties');
    exit;
}

$properties = Property::forOwner($owner);
$rooms = Database::run(
    'SELECT * FROM rooms WHERE owner_name = ? ORDER BY property_name, room_code, name',
    [$owner]
)->fetchAll();
$roomsByProperty = [];
foreach ($rooms as $room) {
    $roomsByProperty[(int) ($room['property_id'] ?? 0)][] = $room;
}

portal_header('owner', 'Properties & rooms', 'properties');
?>
<div class="portal-hero owner-property-hero">
    <div>
        <div class="tagline">One portfolio. Room-level control.</div>
        <h1>Properties &amp; rooms</h1>
        <p>Add properties, view every room and decide the referral points awarded when a referred friend books.</p>
    </div>
    <details class="owner-add-property">
        <summary class="belive-btn-primary">Add property</summary>
        <form method="post" action="/owner/properties" class="owner-property-form">
            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
            <input type="hidden" name="do" value="add_property">
            <div class="belive-field">
                <label for="property-name">Property name</label>
                <input id="property-name" name="name" type="text" required maxlength="120" placeholder="e.g. Setapak Central Residence">
            </div>
            <div class="belive-field">
                <label for="property-location">Area</label>
                <input id="property-location" name="location" type="text" required maxlength="100" placeholder="e.g. Setapak">
            </div>
            <div class="belive-field owner-form-wide">
                <label for="property-address">Full address</label>
                <input id="property-address" name="address" type="text" required maxlength="255">
            </div>
            <div class="belive-field owner-form-wide">
                <label for="property-description">Description <span class="belive-muted">(optional)</span></label>
                <textarea id="property-description" name="description" rows="2" maxlength="1000"></textarea>
            </div>
            <button class="belive-btn-primary owner-form-wide" type="submit">Save property</button>
        </form>
    </details>
</div>

<div class="owner-portfolio-stats" aria-label="Portfolio summary">
    <div><strong><?= count($properties) ?></strong><span>properties</span></div>
    <div><strong><?= count($rooms) ?></strong><span>rooms</span></div>
    <div><strong><?= count(array_filter($rooms, fn (array $room): bool => $room['status'] === 'available')) ?></strong><span>available rooms</span></div>
</div>

<?php if ($properties === []): ?>
    <div class="belive-card owner-property-empty">
        <h2>Add your first property</h2>
        <p class="belive-muted">Create the property first, then add its rooms, tenure prices and referral-point rewards.</p>
    </div>
<?php endif; ?>

<div class="owner-property-list">
<?php foreach ($properties as $property): ?>
    <?php $propertyRooms = $roomsByProperty[(int) $property['id']] ?? []; ?>
    <section class="belive-card owner-property-card" aria-labelledby="property-<?= (int) $property['id'] ?>-heading">
        <header class="owner-property-heading">
            <div>
                <div class="reward-eyebrow">Property</div>
                <h2 id="property-<?= (int) $property['id'] ?>-heading"><?= e($property['name']) ?></h2>
                <p><?= e($property['location']) ?> · <?= e($property['address']) ?></p>
            </div>
            <span class="belive-badge orange"><?= count($propertyRooms) ?> room<?= count($propertyRooms) === 1 ? '' : 's' ?></span>
        </header>
        <?php if ($property['description']): ?><p class="owner-property-description"><?= e($property['description']) ?></p><?php endif; ?>

        <div class="owner-room-list">
        <?php foreach ($propertyRooms as $room): ?>
            <?php $prices = Room::prices((int) $room['id']); ?>
            <article class="owner-room-card">
                <div class="owner-room-main">
                    <div>
                        <div class="owner-room-code"><?= e($room['room_code'] ?: 'No code') ?></div>
                        <h3><?= e($room['name']) ?></h3>
                        <p><?= e(ucfirst($room['room_type'])) ?> room · <?= e(ucfirst($room['status'])) ?><?= $room['available_from'] ? ' · Available ' . e(date('j M Y', strtotime($room['available_from']))) : '' ?></p>
                    </div>
                    <a class="belive-btn-ghost owner-room-link" href="/owner/listings/verify?room_id=<?= (int) $room['id'] ?>">Verification</a>
                </div>

                <div class="owner-room-prices" aria-label="<?= e($room['name']) ?> tenure prices">
                    <?php foreach (Room::TENURES as $tenure): ?>
                        <div>
                            <span><?= e(Room::TENURE_LABELS[$tenure]) ?></span>
                            <strong><?= isset($prices[$tenure]) ? 'RM' . number_format($prices[$tenure]['price']) : 'Not set' ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>

                <form method="post" action="/owner/properties" class="owner-room-points">
                    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                    <input type="hidden" name="do" value="update_referral_points">
                    <input type="hidden" name="room_id" value="<?= (int) $room['id'] ?>">
                    <div>
                        <label for="points-<?= (int) $room['id'] ?>">Referral points for this room</label>
                        <span>Awarded when the referred friend's booking is confirmed.</span>
                    </div>
                    <input id="points-<?= (int) $room['id'] ?>" name="referral_reward_points" type="number" min="0" max="1000" step="1" required value="<?= (int) $room['referral_reward_points'] ?>">
                    <button class="belive-btn-secondary" type="submit">Save points</button>
                </form>
            </article>
        <?php endforeach; ?>

        <?php if ($propertyRooms === []): ?>
            <p class="owner-no-rooms">No rooms yet. Add the first room for this property.</p>
        <?php endif; ?>
        </div>

        <details class="owner-add-room">
            <summary>Add room to <?= e($property['name']) ?></summary>
            <form method="post" action="/owner/properties" class="owner-room-form">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <input type="hidden" name="do" value="add_room">
                <input type="hidden" name="property_id" value="<?= (int) $property['id'] ?>">

                <div class="belive-field">
                    <label for="room-code-<?= (int) $property['id'] ?>">Room code</label>
                    <input id="room-code-<?= (int) $property['id'] ?>" name="room_code" type="text" required maxlength="20" placeholder="A-12-3">
                </div>
                <div class="belive-field">
                    <label for="room-name-<?= (int) $property['id'] ?>">Room name</label>
                    <input id="room-name-<?= (int) $property['id'] ?>" name="name" type="text" required maxlength="120" placeholder="Middle room">
                </div>
                <div class="belive-field">
                    <label for="room-type-<?= (int) $property['id'] ?>">Room type</label>
                    <select id="room-type-<?= (int) $property['id'] ?>" name="room_type" required>
                        <option value="single">Single</option>
                        <option value="middle">Middle</option>
                        <option value="master">Master</option>
                    </select>
                </div>
                <div class="belive-field">
                    <label for="room-status-<?= (int) $property['id'] ?>">Status</label>
                    <select id="room-status-<?= (int) $property['id'] ?>" name="status" required>
                        <option value="available">Available</option>
                        <option value="reserved">Reserved</option>
                        <option value="occupied">Occupied</option>
                    </select>
                </div>
                <div class="belive-field">
                    <label for="room-monthly-<?= (int) $property['id'] ?>">Monthly price (RM)</label>
                    <input id="room-monthly-<?= (int) $property['id'] ?>" name="price_monthly" type="number" min="1" step="0.01" required>
                </div>
                <div class="belive-field">
                    <label for="room-six-<?= (int) $property['id'] ?>">6-month price (RM)</label>
                    <input id="room-six-<?= (int) $property['id'] ?>" name="price_6_month" type="number" min="1" step="0.01" required>
                </div>
                <div class="belive-field">
                    <label for="room-twelve-<?= (int) $property['id'] ?>">12-month price (RM)</label>
                    <input id="room-twelve-<?= (int) $property['id'] ?>" name="price_12_month" type="number" min="1" step="0.01" required>
                </div>
                <div class="belive-field">
                    <label for="room-points-<?= (int) $property['id'] ?>">Referral points</label>
                    <input id="room-points-<?= (int) $property['id'] ?>" name="referral_reward_points" type="number" min="0" max="1000" step="1" required value="<?= REFERRAL_REWARD_POINTS ?>">
                    <div class="hint">Points awarded to the referring tenant after this room's booking is confirmed.</div>
                </div>
                <div class="belive-field">
                    <label for="room-available-<?= (int) $property['id'] ?>">Available from <span class="belive-muted">(optional)</span></label>
                    <input id="room-available-<?= (int) $property['id'] ?>" name="available_from" type="date">
                </div>
                <div class="belive-field owner-form-wide">
                    <label for="room-description-<?= (int) $property['id'] ?>">Room description <span class="belive-muted">(optional)</span></label>
                    <textarea id="room-description-<?= (int) $property['id'] ?>" name="description" rows="2"></textarea>
                </div>
                <button class="belive-btn-primary owner-form-wide" type="submit">Add room</button>
            </form>
        </details>
    </section>
<?php endforeach; ?>
</div>
<?php portal_footer();
