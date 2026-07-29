<?php

declare(strict_types=1);

/**
 * Proves what a tenant sees on /tenant/my_room: the room they actually rent
 * (their signed agreement, not a viewing they once booked), how long they have
 * it for, and rooms recommended to them strictly inside their own area.
 */

use App\Catalog\RoomRepository;
use App\Core\Database;
use App\Models\Booking;
use App\Models\DigitalAgreement;
use App\Models\Lead;
use App\Models\Property;
use App\Models\PropertyUnit;
use App\Models\Room;

$roomViewOwner = 'Room View Test Owner';
$roomViewArea = 'Kepong';

$roomViewProperty = (int) Property::create([
    'owner_name' => $roomViewOwner,
    'name' => 'Room View Residence',
    'location' => $roomViewArea,
    'address' => '3 Room View Road, Kepong',
]);
$roomViewHouseA = (int) PropertyUnit::create(['property_id' => $roomViewProperty, 'name' => 'Unit A-01-1']);
$roomViewHouseB = (int) PropertyUnit::create(['property_id' => $roomViewProperty, 'name' => 'Unit B-02-2']);

/** A priced room, so every candidate has a rate at the tenure being compared. */
$makeRoom = static function (array $attributes, float $twelveMonth) use ($roomViewOwner): int {
    $roomId = Room::create(array_replace([
        'name' => 'Room View Room',
        'location' => 'Kepong',
        'room_type' => 'master',
        'status' => 'available',
        'owner_name' => $roomViewOwner,
        'address' => '3 Room View Road, Kepong',
    ], $attributes));

    Database::run(
        'INSERT INTO room_pricing (room_id, tenure, price, is_best_value) VALUES (?, ?, ?, ?), (?, ?, ?, ?)',
        [$roomId, 'monthly', $twelveMonth + 150, 0, $roomId, '12_month', $twelveMonth, 1]
    );

    return $roomId;
};

$rentedRoom = $makeRoom([
    'name' => 'Room View Master Room',
    'room_code' => 'RV-01',
    'status' => 'occupied',
    'property_id' => $roomViewProperty,
    'unit_id' => $roomViewHouseA,
], 1200);
$sameHouseRoom = $makeRoom([
    'name' => 'Room View Middle Room',
    'room_code' => 'RV-02',
    'room_type' => 'middle',
    'property_id' => $roomViewProperty,
    'unit_id' => $roomViewHouseA,
], 950);
$samePropertyRoom = $makeRoom([
    'name' => 'Room View House B Room',
    'room_code' => 'RV-03',
    'property_id' => $roomViewProperty,
    'unit_id' => $roomViewHouseB,
], 1190);
$sameAreaRoom = $makeRoom([
    'name' => 'Room View Down The Road',
    'room_code' => 'RV-04',
    'address' => '88 Another Road, Kepong',
], 1210);
$occupiedNeighbour = $makeRoom([
    'name' => 'Room View Taken Room',
    'room_code' => 'RV-05',
    'status' => 'occupied',
    'property_id' => $roomViewProperty,
    'unit_id' => $roomViewHouseA,
], 1195);
$otherAreaRoom = $makeRoom([
    'name' => 'Room View Cheras Room',
    'room_code' => 'RV-06',
    'location' => 'Cheras',
    'address' => '5 Faraway Street, Cheras',
], 1205);

$roomViewTenant = Lead::create(['wa_phone' => '60112000901', 'name' => 'Room View Tenant', 'source_channel' => 'whatsapp']);

// The room they once viewed, so "my room" cannot just mean "my latest booking".
$viewedRoom = $makeRoom([
    'name' => 'Room View Only Viewed Room',
    'room_code' => 'RV-07',
], 1300);
Booking::create([
    'lead_id' => $roomViewTenant,
    'room_id' => $viewedRoom,
    'viewing_datetime' => date('Y-m-d H:i:s', strtotime('-40 day')),
    'status' => 'pending',
]);

// A tenancy that has already ended, plus the one they are living in now.
DigitalAgreement::create([
    'lead_id' => $roomViewTenant,
    'room_id' => $sameAreaRoom,
    'agreement_text' => 'Room view — last year’s tenancy.',
    'status' => 'completed',
    'owner_name' => $roomViewOwner,
    'tenure' => '12_month',
    'starts_on' => date('Y-m-d', strtotime('-720 day')),
    'ends_on' => date('Y-m-d', strtotime('-355 day')),
    'monthly_rent_rm' => 1100.00,
    'access_code' => 'RVW000001',
]);
$liveAgreementId = DigitalAgreement::create([
    'lead_id' => $roomViewTenant,
    'room_id' => $rentedRoom,
    'agreement_text' => 'Room view — the tenancy they are in.',
    'status' => 'completed',
    'owner_name' => $roomViewOwner,
    'tenure' => '12_month',
    'starts_on' => date('Y-m-d', strtotime('-120 day')),
    'ends_on' => date('Y-m-d', strtotime('+244 day')),
    'monthly_rent_rm' => 1200.00,
    'access_code' => 'RVW000002',
]);

// ---- the room they rent -------------------------------------------------------
$current = DigitalAgreement::currentForTenant($roomViewTenant);
check('a tenant sees the room they rent, not the one they only viewed',
    $current !== null
    && (int) $current['id'] === $liveAgreementId
    && (int) $current['room_id'] === $rentedRoom
    && $current['room_name'] === 'Room View Master Room');
check('the room carries its place in the portfolio',
    $current['property_name'] === 'Room View Residence'
    && $current['house_name'] === 'Unit A-01-1'
    && $current['location'] === $roomViewArea);

$unsignedTenant = Lead::create(['wa_phone' => '60112000902', 'name' => 'Room View Unsigned Tenant', 'source_channel' => 'whatsapp']);
DigitalAgreement::create([
    'lead_id' => $unsignedTenant,
    'room_id' => $samePropertyRoom,
    'agreement_text' => 'Room view — waiting on a signature.',
    'status' => 'tenant_review',
    'owner_name' => $roomViewOwner,
    'tenure' => '12_month',
    'starts_on' => date('Y-m-d', strtotime('+10 day')),
    'ends_on' => date('Y-m-d', strtotime('+374 day')),
    'monthly_rent_rm' => 1190.00,
    'access_code' => 'RVW000003',
]);
check('an unsigned agreement is not yet a room anyone rents',
    DigitalAgreement::currentForTenant($unsignedTenant) === null);

// ---- how long they have it for ------------------------------------------------
$timeline = DigitalAgreement::timeline($current);
check('the rental duration counts down from the agreement dates',
    $timeline !== null
    && $timeline['state'] === 'active'
    && $timeline['days_remaining'] === 244
    && $timeline['total_days'] === 365);
check('the whole term is named in months when the dates make whole months',
    DigitalAgreement::termLabel(['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']) === '12 months'
    && DigitalAgreement::termLabel(['starts_on' => '2026-03-01', 'ends_on' => '2026-08-31']) === '6 months'
    && DigitalAgreement::termLabel(['starts_on' => '2026-03-01', 'ends_on' => '2026-03-31']) === '1 month');
check('a term that is not whole months is named in days',
    DigitalAgreement::termLabel(['starts_on' => '2026-03-01', 'ends_on' => '2026-03-20']) === '20 days');
check('undated legacy agreements have no term to name',
    DigitalAgreement::termLabel(['starts_on' => null, 'ends_on' => null]) === null);

// ---- similar rooms, same area only --------------------------------------------
$tenantsRoom = RoomRepository::findWithHierarchy($rentedRoom);
$similar = RoomRepository::similarInArea($tenantsRoom, '12_month', 1200.00);
$similarIds = array_map(static fn (array $r): int => (int) $r['id'], $similar);

check('recommendations never leave the tenant’s own area',
    $similar !== []
    && !in_array($otherAreaRoom, $similarIds, true)
    && array_values(array_unique(array_column($similar, 'location'))) === [$roomViewArea]);
check('a tenant is never recommended their own room, or one already taken',
    !in_array($rentedRoom, $similarIds, true)
    && !in_array($occupiedNeighbour, $similarIds, true));
check('the nearest rooms come first — same house, then same property',
    $similarIds[0] === $sameHouseRoom
    && $similarIds[1] === $samePropertyRoom
    && in_array($sameAreaRoom, $similarIds, true));
check('recommendations are priced at the tenure the tenant is on',
    (float) $similar[0]['price_at_tenure'] === 950.0
    && isset($similar[0]['prices']['12_month'], $similar[0]['amenities'])
    && array_key_exists('cover_image', $similar[0]));

$loneRoom = $makeRoom([
    'name' => 'Room View Lone Room',
    'room_code' => 'RV-08',
    'location' => 'Sungai Buloh',
    'status' => 'occupied',
    'address' => '1 Lonely Lane, Sungai Buloh',
], 900);
check('an area with nothing else free recommends nothing rather than a room across town',
    RoomRepository::similarInArea(RoomRepository::findWithHierarchy($loneRoom), '12_month') === []);
