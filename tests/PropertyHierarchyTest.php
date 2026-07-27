<?php

declare(strict_types=1);

/**
 * Proves the Property → House → Room hierarchy the admin room page drills
 * through: houses belong to a property, rooms belong to a house, a room can
 * only move between houses of its own property, and the tenant count on a
 * room means "confirmed or completed booking" — not "booked a viewing".
 */

use App\Core\Database;
use App\Models\Booking;
use App\Models\Lead;
use App\Models\Property;
use App\Models\PropertyUnit;
use App\Models\Room;
use App\Properties\PropertyManager;
use App\Properties\PropertyReviewManager;

$hierarchyOwner = 'Hierarchy Test Owner';
$residence = PropertyManager::addProperty($hierarchyOwner, [
    'name' => 'Hierarchy Residence',
    'location' => 'Petaling Jaya',
    'address' => '121 Hierarchy Road, Petaling Jaya',
]);

$houseBeforeApprovalBlocked = false;
try {
    PropertyManager::addUnitForAdmin((int) $residence['id'], ['name' => 'Unit A-12-3']);
} catch (RuntimeException) {
    $houseBeforeApprovalBlocked = true;
}
check('a house cannot be added before the property is approved', $houseBeforeApprovalBlocked);

$residence = PropertyReviewManager::review(
    (int) $residence['id'],
    'approved',
    'hierarchy-admin',
    'Approved for the hierarchy test.',
    (int) $residence['review_version']
);

$houseA = PropertyManager::addUnitForAdmin((int) $residence['id'], [
    'name' => 'Unit A-12-3',
    'notes' => '3 bedrooms, level 12',
]);
$houseB = PropertyManager::addUnitForAdmin((int) $residence['id'], ['name' => 'Unit B-04-1']);
check('admin can add houses to an approved property',
    (int) $houseA['property_id'] === (int) $residence['id']
    && $houseA['name'] === 'Unit A-12-3'
    && count(PropertyUnit::forProperty((int) $residence['id'])) === 2);

$duplicateHouseBlocked = false;
try {
    PropertyManager::addUnitForAdmin((int) $residence['id'], ['name' => 'Unit A-12-3']);
} catch (RuntimeException) {
    $duplicateHouseBlocked = true;
}
check('the same house name cannot be added twice to one property', $duplicateHouseBlocked);

$namelessHouseBlocked = false;
try {
    PropertyManager::addUnitForAdmin((int) $residence['id'], ['name' => '   ']);
} catch (InvalidArgumentException) {
    $namelessHouseBlocked = true;
}
check('a house must be named', $namelessHouseBlocked);

$baseRoom = [
    'room_code' => 'HR-01',
    'name' => 'Hierarchy Master Room',
    'room_type' => 'master',
    'status' => 'occupied',
    'price_monthly' => '1200',
    'price_6_month' => '1150',
    'price_12_month' => '1100',
    'referral_reward_points' => '60',
];

$roomInHouseA = PropertyManager::addRoomForAdmin((int) $residence['id'], array_replace($baseRoom, [
    'unit_id' => (int) $houseA['id'],
]));
check('a room added through a house belongs to that house',
    (int) $roomInHouseA['unit_id'] === (int) $houseA['id']
    && (int) $roomInHouseA['property_id'] === (int) $residence['id']);

// The owner portal and the importer do not know about houses yet.
$roomWithoutHouse = PropertyManager::addRoom($hierarchyOwner, array_replace($baseRoom, [
    'property_id' => (int) $residence['id'],
    'room_code' => 'HR-02',
    'name' => 'Hierarchy Middle Room',
    'room_type' => 'middle',
    'status' => 'available',
]));
check('a room added without naming a house still lands inside one',
    (int) $roomWithoutHouse['unit_id'] > 0
    && in_array((int) $roomWithoutHouse['unit_id'], [(int) $houseA['id'], (int) $houseB['id']], true));

$otherProperty = PropertyReviewManager::review(
    (int) PropertyManager::addProperty($hierarchyOwner, [
        'name' => 'Other Hierarchy Residence',
        'location' => 'Cheras',
        'address' => '9 Elsewhere Street, Cheras',
    ])['id'],
    'approved',
    'hierarchy-admin',
    'Second property for the cross-property check.',
    1
);
$foreignHouse = PropertyManager::addUnitForAdmin((int) $otherProperty['id'], ['name' => 'Unit C-01-1']);

$crossPropertyMoveBlocked = false;
try {
    PropertyManager::updateRoomForAdmin((int) $roomInHouseA['id'], array_replace($baseRoom, [
        'unit_id' => (int) $foreignHouse['id'],
    ]));
} catch (RuntimeException) {
    $crossPropertyMoveBlocked = true;
}
check('a room cannot be moved into a house on another property', $crossPropertyMoveBlocked);

$movedRoom = PropertyManager::updateRoomForAdmin((int) $roomInHouseA['id'], array_replace($baseRoom, [
    'unit_id' => (int) $houseB['id'],
]));
check('admin can move a room to another house on the same property',
    (int) $movedRoom['unit_id'] === (int) $houseB['id']);

$keptRoom = PropertyManager::updateRoomForAdmin((int) $roomInHouseA['id'], $baseRoom);
check('editing a room without naming a house leaves it where it is',
    (int) $keptRoom['unit_id'] === (int) $houseB['id']);

$renamedHouse = PropertyManager::updateUnitForAdmin((int) $houseB['id'], [
    'name' => 'Unit B-04-1 (renovated)',
    'notes' => 'Repainted July 2026',
]);
check('admin can rename a house and its rooms follow it',
    $renamedHouse['name'] === 'Unit B-04-1 (renovated)'
    && (int) Room::find((int) $roomInHouseA['id'])['unit_id'] === (int) $houseB['id']);

// ---- tenants: a confirmed or completed booking, nothing looser ---------------
$tenantOne = Lead::create(['wa_phone' => '60110000901', 'name' => 'Hierarchy Tenant One', 'source_channel' => 'whatsapp']);
$tenantTwo = Lead::create(['wa_phone' => '60110000902', 'name' => 'Hierarchy Tenant Two', 'source_channel' => 'whatsapp']);
$viewerOnly = Lead::create(['wa_phone' => '60110000903', 'name' => 'Hierarchy Viewer', 'source_channel' => 'whatsapp']);
$cancelled = Lead::create(['wa_phone' => '60110000904', 'name' => 'Hierarchy Cancelled', 'source_channel' => 'whatsapp']);

Booking::create(['lead_id' => $tenantOne, 'room_id' => (int) $roomInHouseA['id'], 'viewing_datetime' => '2026-06-01 14:00:00', 'status' => 'confirmed']);
Booking::create(['lead_id' => $tenantOne, 'room_id' => (int) $roomInHouseA['id'], 'viewing_datetime' => '2026-06-08 14:00:00', 'status' => 'completed']);
Booking::create(['lead_id' => $tenantTwo, 'room_id' => (int) $roomInHouseA['id'], 'viewing_datetime' => '2026-06-02 11:00:00', 'status' => 'completed']);
Booking::create(['lead_id' => $viewerOnly, 'room_id' => (int) $roomInHouseA['id'], 'viewing_datetime' => '2026-06-03 11:00:00', 'status' => 'pending']);
Booking::create(['lead_id' => $cancelled, 'room_id' => (int) $roomInHouseA['id'], 'viewing_datetime' => '2026-06-04 11:00:00', 'status' => 'cancelled']);

$tenants = Room::tenants((int) $roomInHouseA['id']);
check('a room counts each renting tenant once, and only confirmed or completed bookings',
    count($tenants) === 2
    && array_column($tenants, 'id') === [$tenantOne, $tenantTwo]);
check('the tenant list carries the name and WhatsApp number admin needs',
    $tenants[0]['name'] === 'Hierarchy Tenant One'
    && $tenants[0]['wa_phone'] === '60110000901');

$houseCounts = PropertyUnit::countsForProperty((int) $residence['id']);
$roomHouseId = (int) Room::find((int) $roomInHouseA['id'])['unit_id'];
$otherHouseId = $roomHouseId === (int) $houseA['id'] ? (int) $houseB['id'] : (int) $houseA['id'];
check('house counts report its rooms and the tenants inside them',
    $houseCounts[$roomHouseId]['rooms'] === 1
    && $houseCounts[$roomHouseId]['tenants'] === 2
    && $houseCounts[$roomHouseId]['available'] === 0);
check('a house with no tenants reports zero rather than dropping out',
    isset($houseCounts[$otherHouseId])
    && $houseCounts[$otherHouseId]['tenants'] === 0);

$portfolio = Property::portfolioCounts([(int) $residence['id'], (int) $otherProperty['id']]);
check('property counts roll up its houses, rooms and tenants',
    $portfolio[(int) $residence['id']]['houses'] === 2
    && $portfolio[(int) $residence['id']]['rooms'] === 2
    && $portfolio[(int) $residence['id']]['tenants'] === 2
    && $portfolio[(int) $residence['id']]['available'] === 1);
check('a property with houses but no rooms still appears with zeroes',
    $portfolio[(int) $otherProperty['id']]['houses'] === 1
    && $portfolio[(int) $otherProperty['id']]['rooms'] === 0
    && $portfolio[(int) $otherProperty['id']]['tenants'] === 0);

check('every room on an approved property is reachable through a house',
    (int) Database::run(
        "SELECT COUNT(*) FROM rooms r
         JOIN properties p ON p.id = r.property_id
         WHERE p.review_status = 'approved' AND r.unit_id IS NULL"
    )->fetchColumn() === 0);
