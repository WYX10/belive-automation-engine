<?php

declare(strict_types=1);

/**
 * Proves the renewal promo an owner offers a tenant whose term is running out:
 * it can only be made inside the last 30 days, only below what the tenant pays
 * now, only one at a time, only by the owner who holds the tenancy, and only
 * answered by the tenant it was made to — and that accepting one never touches
 * the signed agreement.
 */

use App\Core\Database;
use App\Models\DigitalAgreement;
use App\Models\ElectricBill;
use App\Models\Lead;
use App\Models\Property;
use App\Models\PropertyUnit;
use App\Models\RenewalOffer;
use App\Models\Room;
use App\Renewals\RenewalOfferManager;

$renewalOwner = 'Renewal Test Owner';
$otherOwner = 'Renewal Other Owner';

$renewalRoomId = Room::create([
    'name' => 'Renewal Master Room',
    'location' => 'Setapak',
    'room_type' => 'master',
    'status' => 'occupied',
    'owner_name' => $renewalOwner,
    'address' => '7 Renewal Road, Setapak',
]);
Database::run(
    'INSERT INTO room_pricing (room_id, tenure, price, is_best_value) VALUES (?, ?, ?, ?), (?, ?, ?, ?)',
    [$renewalRoomId, 'monthly', 1300, 0, $renewalRoomId, '12_month', 1200, 1]
);

// One lead per tenancy, the way the data actually looks — so a check about
// what a tenant has waiting is about that tenant's own tenancy.
$renewalTenant = Lead::create(['wa_phone' => '60112000801', 'name' => 'Renewal Tenant', 'source_channel' => 'whatsapp']);
$otherTenant = Lead::create(['wa_phone' => '60112000802', 'name' => 'Renewal Other Tenant', 'source_channel' => 'whatsapp']);
$thirtyTenant = Lead::create(['wa_phone' => '60112000803', 'name' => 'Renewal Thirty-Day Tenant', 'source_channel' => 'whatsapp']);
$earlyTenant = Lead::create(['wa_phone' => '60112000804', 'name' => 'Renewal Early Tenant', 'source_channel' => 'whatsapp']);

/** A signed tenancy ending $daysFromToday days from now. */
$makeTenancy = static function (
    int $daysFromToday,
    int $leadId,
    int $roomId,
    string $ownerName,
    ?float $rent = 1200.00,
    string $status = 'completed'
) use (&$agreementSeq): int {
    $agreementSeq = ($agreementSeq ?? 0) + 1;

    return DigitalAgreement::create([
        'lead_id' => $leadId,
        'room_id' => $roomId,
        'agreement_text' => 'Renewal test tenancy.',
        'status' => $status,
        'owner_name' => $ownerName,
        'tenure' => '12_month',
        'starts_on' => date('Y-m-d', strtotime('-300 day')),
        // "$n day" — never "+$n day", which strtotime reads as +n even when n
        // is negative.
        'ends_on' => date('Y-m-d', strtotime("$daysFromToday day")),
        'monthly_rent_rm' => $rent,
        'access_code' => 'RNW' . str_pad((string) $agreementSeq, 6, '0', STR_PAD_LEFT),
    ]);
};

// ---- the window: only in the last 30 days -----------------------------------
$tooEarly = $makeTenancy(90, $earlyTenant, $renewalRoomId, $renewalOwner);
$tooEarlyBlocked = false;
try {
    RenewalOfferManager::offer($renewalOwner, $tooEarly, ['tenure' => '12_month', 'promo_rent_rm' => '1100']);
} catch (RuntimeException) {
    $tooEarlyBlocked = true;
}
check('a tenancy with 90 days left cannot be offered a renewal price yet', $tooEarlyBlocked);

$exactlyThirty = $makeTenancy(30, $thirtyTenant, $renewalRoomId, $renewalOwner);
$thirtyDayOffer = RenewalOfferManager::offer($renewalOwner, $exactlyThirty, [
    'tenure' => '12_month',
    'promo_rent_rm' => '1100',
]);
check('the offer window opens at exactly 30 days left, matching the ending-soon badge',
    (int) $thirtyDayOffer['agreement_id'] === $exactlyThirty
    && (float) $thirtyDayOffer['promo_rent_rm'] === 1100.0
    && DigitalAgreement::ENDING_SOON_DAYS === 30);

check('the offer snapshots what the tenant pays now, not what the room is listed at',
    (float) $thirtyDayOffer['current_rent_rm'] === 1200.0);
check('the new term starts the day after the current one ends',
    $thirtyDayOffer['starts_on'] === date('Y-m-d', strtotime('+31 day'))
    && $thirtyDayOffer['ends_on'] === date('Y-m-d', strtotime('+31 day +12 month -1 day')));
check('the offer defaults to expiring when the tenancy does',
    $thirtyDayOffer['expires_on'] === date('Y-m-d', strtotime('+30 day')));

$endsToday = $makeTenancy(0, $otherTenant, $renewalRoomId, $renewalOwner);
$lastDayOffer = RenewalOfferManager::offer($renewalOwner, $endsToday, [
    'tenure' => 'monthly',
    'promo_rent_rm' => '1000',
    'message' => 'Stay on month to month at this rate.',
]);
check('a tenancy ending today can still be offered a price', $lastDayOffer['status'] === 'offered');

$expiredTenancy = $makeTenancy(-5, $earlyTenant, $renewalRoomId, $renewalOwner);
$expiredBlocked = false;
try {
    RenewalOfferManager::offer($renewalOwner, $expiredTenancy, ['tenure' => '12_month', 'promo_rent_rm' => '1000']);
} catch (RuntimeException) {
    $expiredBlocked = true;
}
check('a tenancy that already ended cannot be offered a renewal price', $expiredBlocked);

$unsigned = $makeTenancy(10, $earlyTenant, $renewalRoomId, $renewalOwner, 1200.00, 'owner_review');
$unsignedBlocked = false;
try {
    RenewalOfferManager::offer($renewalOwner, $unsigned, ['tenure' => '12_month', 'promo_rent_rm' => '1000']);
} catch (RuntimeException) {
    $unsignedBlocked = true;
}
check('an agreement still being signed has no term to renew', $unsignedBlocked);

// ---- the price must be a promotion ------------------------------------------
$priceTenancy = $makeTenancy(14, $renewalTenant, $renewalRoomId, $renewalOwner);

$samePriceBlocked = false;
try {
    RenewalOfferManager::offer($renewalOwner, $priceTenancy, ['tenure' => '12_month', 'promo_rent_rm' => '1200']);
} catch (InvalidArgumentException) {
    $samePriceBlocked = true;
}
check('a "promo" at the same rent is rejected', $samePriceBlocked);

$higherPriceBlocked = false;
try {
    RenewalOfferManager::offer($renewalOwner, $priceTenancy, ['tenure' => '12_month', 'promo_rent_rm' => '1400']);
} catch (InvalidArgumentException) {
    $higherPriceBlocked = true;
}
check('a renewal price above the current rent is not a promotion and is rejected', $higherPriceBlocked);

$zeroPriceBlocked = false;
try {
    RenewalOfferManager::offer($renewalOwner, $priceTenancy, ['tenure' => '12_month', 'promo_rent_rm' => '0']);
} catch (InvalidArgumentException) {
    $zeroPriceBlocked = true;
}
check('a free or negative renewal price is rejected', $zeroPriceBlocked);

$badTenureBlocked = false;
try {
    RenewalOfferManager::offer($renewalOwner, $priceTenancy, ['tenure' => '18_month', 'promo_rent_rm' => '1000']);
} catch (InvalidArgumentException) {
    $badTenureBlocked = true;
}
check('the renewal length must be one BeLive actually sells', $badTenureBlocked);

$lateExpiryBlocked = false;
try {
    RenewalOfferManager::offer($renewalOwner, $priceTenancy, [
        'tenure' => '12_month',
        'promo_rent_rm' => '1000',
        'expires_on' => date('Y-m-d', strtotime('+60 day')),
    ]);
} catch (InvalidArgumentException) {
    $lateExpiryBlocked = true;
}
check('an offer cannot outlive the tenancy it renews', $lateExpiryBlocked);

// ---- one open offer at a time ------------------------------------------------
$firstOffer = RenewalOfferManager::offer($renewalOwner, $priceTenancy, [
    'tenure' => '12_month',
    'promo_rent_rm' => '1050',
]);
$secondBlocked = false;
try {
    RenewalOfferManager::offer($renewalOwner, $priceTenancy, ['tenure' => '12_month', 'promo_rent_rm' => '1000']);
} catch (RuntimeException) {
    $secondBlocked = true;
}
check('a tenant is never shown two live prices for the same tenancy', $secondBlocked);

// ---- ownership and tenant scoping -------------------------------------------
$foreignOfferBlocked = false;
try {
    RenewalOfferManager::offer($otherOwner, $exactlyThirty, ['tenure' => '12_month', 'promo_rent_rm' => '900']);
} catch (RuntimeException) {
    $foreignOfferBlocked = true;
}
check('another owner cannot offer a price on this owner\'s tenancy', $foreignOfferBlocked);

$foreignWithdrawBlocked = false;
try {
    RenewalOfferManager::withdraw($otherOwner, (int) $firstOffer['id']);
} catch (RuntimeException) {
    $foreignWithdrawBlocked = true;
}
check('another owner cannot withdraw this owner\'s offer', $foreignWithdrawBlocked);

$foreignAnswerBlocked = false;
try {
    RenewalOfferManager::respond($otherTenant, (int) $firstOffer['id'], 'accepted');
} catch (RuntimeException) {
    $foreignAnswerBlocked = true;
}
check('an offer can only be answered by the tenant it was made to', $foreignAnswerBlocked);

// ---- withdraw, then re-offer -------------------------------------------------
$withdrawn = RenewalOfferManager::withdraw($renewalOwner, (int) $firstOffer['id']);
check('withdrawing closes the offer and stamps when', $withdrawn['status'] === 'withdrawn'
    && $withdrawn['responded_at'] !== null);

$withdrawnAnswerBlocked = false;
try {
    RenewalOfferManager::respond($renewalTenant, (int) $firstOffer['id'], 'accepted');
} catch (RuntimeException) {
    $withdrawnAnswerBlocked = true;
}
check('a withdrawn offer cannot still be accepted', $withdrawnAnswerBlocked);

$replacement = RenewalOfferManager::offer($renewalOwner, $priceTenancy, [
    'tenure' => '6_month',
    'promo_rent_rm' => '1150',
    'message' => 'Six months instead?',
]);
check('a fresh price can be offered once the old one is withdrawn', $replacement['status'] === 'offered'
    && (float) $replacement['promo_rent_rm'] === 1150.0);

// ---- the tenant answers ------------------------------------------------------
check('the tenant sees exactly one live offer',
    (int) (RenewalOffer::openForTenant($renewalTenant)['id'] ?? 0) === (int) $replacement['id']);
check('the saving shown is the difference from what they pay now',
    RenewalOffer::monthlySaving($replacement) === 50.0);

$agreementBefore = DigitalAgreement::find($priceTenancy);
$accepted = RenewalOfferManager::respond($renewalTenant, (int) $replacement['id'], 'accepted', 'Yes please.');
check('accepting records the answer, when, and what the tenant said',
    $accepted['status'] === 'accepted'
    && $accepted['responded_at'] !== null
    && $accepted['response_note'] === 'Yes please.');
check('accepting a renewal price never edits the signed agreement',
    DigitalAgreement::find($priceTenancy) == $agreementBefore);
check('an answered offer leaves the tenant nothing outstanding to act on',
    RenewalOffer::openForTenant($renewalTenant) === null);

$twiceBlocked = false;
try {
    RenewalOfferManager::respond($renewalTenant, (int) $replacement['id'], 'declined');
} catch (RuntimeException) {
    $twiceBlocked = true;
}
check('an offer cannot be answered twice', $twiceBlocked);

$declineTenancy = $makeTenancy(20, $otherTenant, $renewalRoomId, $renewalOwner, 1500.00);
$declinable = RenewalOfferManager::offer($renewalOwner, $declineTenancy, [
    'tenure' => '12_month',
    'promo_rent_rm' => '1400',
]);
$declined = RenewalOfferManager::respond($otherTenant, (int) $declinable['id'], 'declined');
check('declining is recorded just as plainly as accepting', $declined['status'] === 'declined'
    && $declined['responded_at'] !== null);

$nonsenseAnswerBlocked = false;
try {
    RenewalOfferManager::respond($thirtyTenant, (int) $thirtyDayOffer['id'], 'maybe');
} catch (InvalidArgumentException) {
    $nonsenseAnswerBlocked = true;
}
check('an answer must be accept or decline', $nonsenseAnswerBlocked);

// ---- expiry is derived, never stored ------------------------------------------
Database::run(
    'UPDATE renewal_offers SET expires_on = ? WHERE id = ?',
    [date('Y-m-d', strtotime('-1 day')), (int) $thirtyDayOffer['id']]
);
$lapsed = RenewalOffer::find((int) $thirtyDayOffer['id']);
check('an offer past its date reads as expired while still stored as offered',
    $lapsed['status'] === 'offered' && RenewalOffer::state($lapsed) === 'expired' && !RenewalOffer::isOpen($lapsed));

$expiredAnswerBlocked = false;
try {
    RenewalOfferManager::respond($thirtyTenant, (int) $thirtyDayOffer['id'], 'accepted');
} catch (RuntimeException) {
    $expiredAnswerBlocked = true;
}
check('an expired offer cannot be accepted', $expiredAnswerBlocked);

check('an expired offer is not counted as open for the tenant',
    RenewalOffer::openForTenant($thirtyTenant) === null);

// A lapsed offer frees the tenancy for a new one — the owner is not locked out
// by a price nobody can accept any more.
$afterLapse = RenewalOfferManager::offer($renewalOwner, $exactlyThirty, [
    'tenure' => '12_month',
    'promo_rent_rm' => '1080',
]);
check('an expired offer no longer blocks a new one', $afterLapse['status'] === 'offered');

// ---- the owner's tenancy list -------------------------------------------------
$tenancies = DigitalAgreement::tenanciesForOwner($renewalOwner);
$listedIds = array_map(static fn (array $row): int => (int) $row['id'], $tenancies);
check('the owner sees their signed tenancies and not the unsigned draft',
    in_array($exactlyThirty, $listedIds, true) && !in_array($unsigned, $listedIds, true));
$endDates = array_column($tenancies, 'ends_on');
$sortedEndDates = $endDates;
sort($sortedEndDates);
check('tenancies are listed with whoever is closest to leaving first',
    count($endDates) > 1 && $endDates === $sortedEndDates);
check('another owner sees none of them', DigitalAgreement::tenanciesForOwner($otherOwner) === []);

$latest = RenewalOffer::latestForAgreements($listedIds);
check('the tenancies page loads the newest offer per tenancy in one query',
    (int) ($latest[$priceTenancy]['id'] ?? 0) === (int) $replacement['id']
    && (int) ($latest[$exactlyThirty]['id'] ?? 0) === (int) $afterLapse['id']);
check('a tenancy with no offer simply has none', RenewalOffer::latestForAgreements([$tooEarly]) === []);

// ---- owner utilities read: house name, and no tenant identity -----------------
$utilProperty = Property::create([
    'owner_name' => $renewalOwner,
    'name' => 'Renewal Utilities Residence',
    'location' => 'Setapak',
    'address' => '7 Renewal Road, Setapak',
]);
$utilHouse = PropertyUnit::create(['property_id' => $utilProperty, 'name' => 'Unit R-01-2']);
$meteredRoom = Room::create([
    'name' => 'Metered Room',
    'location' => 'Setapak',
    'room_type' => 'single',
    'owner_name' => $renewalOwner,
    'address' => '7 Renewal Road, Setapak',
    'property_id' => $utilProperty,
    'unit_id' => $utilHouse,
    'room_code' => 'RNW-01',
]);
$unmeteredRoom = Room::create([
    'name' => 'Unmetered Room',
    'location' => 'Setapak',
    'room_type' => 'single',
    'owner_name' => $renewalOwner,
    'address' => '7 Renewal Road, Setapak',
    'property_id' => $utilProperty,
    'unit_id' => $utilHouse,
    'room_code' => 'RNW-02',
]);

$meter = ElectricBill::registerMeter($meteredRoom, 'RENEWAL-METER-01', 0.52);
ElectricBill::issue([
    'meter_id' => (int) $meter['id'],
    'lead_id' => $renewalTenant,
    'period_start' => '2026-05-01',
    'period_end' => '2026-05-31',
    'previous_reading' => 1000,
    'current_reading' => 1120,
    'reading_source' => 'smart_meter',
]);
ElectricBill::issue([
    'meter_id' => (int) $meter['id'],
    'lead_id' => $renewalTenant,
    'period_start' => '2026-06-01',
    'period_end' => '2026-06-30',
    'previous_reading' => 1120,
    'current_reading' => 1205,
    'reading_source' => 'smart_meter',
]);

$utilities = ElectricBill::latestForOwnerRooms($renewalOwner);
$byRoom = [];
foreach ($utilities as $row) {
    $byRoom[(int) $row['room_id']] = $row;
}
check('the owner utilities read names the house each room sits in',
    ($byRoom[$meteredRoom]['house_name'] ?? null) === 'Unit R-01-2'
    && ($byRoom[$meteredRoom]['property_name'] ?? null) === 'Renewal Utilities Residence');
check('it reports the newest closed billing period, not an older one',
    (float) $byRoom[$meteredRoom]['units_kwh'] === 85.0
    && $byRoom[$meteredRoom]['period_start'] === '2026-06-01');
check('a room with no meter still appears, with nothing invented for it',
    array_key_exists($unmeteredRoom, $byRoom)
    && $byRoom[$unmeteredRoom]['units_kwh'] === null
    && $byRoom[$unmeteredRoom]['meter_serial'] === null);
check('the owner utilities read carries no tenant identity at all',
    !array_key_exists('lead_id', $byRoom[$meteredRoom])
    && !array_key_exists('tenant_name', $byRoom[$meteredRoom]));
check('another owner sees none of these rooms', ElectricBill::latestForOwnerRooms($otherOwner) === []);
