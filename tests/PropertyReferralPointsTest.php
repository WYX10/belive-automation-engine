<?php

declare(strict_types=1);

/**
 * Proves owners can create properties/rooms, configure room-level referral
 * points, and that booking confirmation snapshots the booked room and points
 * onto the referring tenant's ledger row.
 */

use App\Models\Booking;
use App\Core\Database;
use App\Integrations\WhatsApp\WhatsAppClient;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Referral;
use App\Models\ReferralRedemption;
use App\Models\Room;
use App\Pipeline\Booking\ConfirmationSender;
use App\Pipeline\Referral\ReferralRewardWebhook;
use App\Properties\PropertyManager;

$ownerName = 'Property Points Test Owner';
$property = PropertyManager::addProperty($ownerName, [
    'name' => 'Referral Point Residence',
    'location' => 'Cheras',
    'address' => '22 Reward Road, Cheras',
    'description' => 'Property created by the room-level referral test.',
]);
check('owner can add a property', $property['owner_name'] === $ownerName
    && $property['name'] === 'Referral Point Residence');
check('property appears in the owner portfolio', count(Property::forOwner($ownerName)) === 1);

$duplicatePropertyBlocked = false;
try {
    PropertyManager::addProperty($ownerName, [
        'name' => 'Referral Point Residence',
        'location' => 'Cheras',
        'address' => 'Another address',
    ]);
} catch (RuntimeException) {
    $duplicatePropertyBlocked = true;
}
check('duplicate owner property is rejected', $duplicatePropertyBlocked);

$room = PropertyManager::addRoom($ownerName, [
    'property_id' => $property['id'],
    'room_code' => 'RPR-01',
    'name' => 'Referral Master Room',
    'room_type' => 'master',
    'status' => 'available',
    'price_monthly' => '950',
    'price_6_month' => '900',
    'price_12_month' => '850',
    'referral_reward_points' => '125',
    'available_from' => '2026-08-01',
]);
check('owner can add a room under their property', (int) $room['property_id'] === (int) $property['id']
    && $room['property_name'] === $property['name']);
check('new room stores owner-selected referral points', (int) $room['referral_reward_points'] === 125);
$roomPrices = Room::prices((int) $room['id']);
check('new room stores all three tenure prices', count($roomPrices) === 3
    && $roomPrices['monthly']['price'] === 950.0
    && $roomPrices['12_month']['price'] === 850.0);

$updatedRoom = PropertyManager::updateReferralPoints($ownerName, (int) $room['id'], '175');
check('owner can update points for their own room', (int) $updatedRoom['referral_reward_points'] === 175);

$crossOwnerBlocked = false;
try {
    PropertyManager::updateReferralPoints('Different Owner', (int) $room['id'], '999');
} catch (RuntimeException) {
    $crossOwnerBlocked = true;
}
check('owner cannot update another owner room points', $crossOwnerBlocked
    && (int) Room::find((int) $room['id'])['referral_reward_points'] === 175);

$referrerId = Lead::create([
    'wa_phone' => '601177770001',
    'name' => 'Room Points Referrer',
    'source_channel' => 'whatsapp',
]);
$friendId = Lead::create([
    'wa_phone' => '601177770002',
    'name' => 'Referred Booking Friend',
    'source_channel' => 'referral',
]);
$code = Referral::codeFor($referrerId);
check('friend is attributed to tenant referral code', Referral::attachReferredLead($code, $friendId));

$otherReferrerId = Lead::create([
    'wa_phone' => '601177770003',
    'name' => 'Second Code Owner',
    'source_channel' => 'whatsapp',
]);
$otherCode = Referral::codeFor($otherReferrerId);
check('one friend cannot be attributed to a second referral code',
    Referral::attachReferredLead($otherCode, $friendId) === false);

$bookingId = Booking::create([
    'lead_id' => $friendId,
    'room_id' => $room['id'],
    'viewing_datetime' => '2026-08-15 14:00:00',
    'status' => 'confirmed',
]);
$booking = Booking::find($bookingId);
ConfirmationSender::send($booking, 'mock-offline-stub');

$credited = Referral::findByCode($code);
check('booking confirmation credits the booked room point value', $credited['reward_status'] === 'credited'
    && (int) $credited['reward_points'] === 175);
check('referral ledger records which room produced the reward', (int) $credited['reward_room_id'] === (int) $room['id']);
check('repeated booking reward event cannot credit the same referral twice',
    ReferralRewardWebhook::onBookingConfirmed($friendId, (int) $room['id']) === null);
$history = Referral::forReferrer($referrerId);
check('tenant referral history resolves the booked property and room', $history[0]['reward_property_name'] === $property['name']
    && $history[0]['reward_room_name'] === $room['name']);
Database::run('UPDATE referrals SET reward_room_id = NULL WHERE id = ?', [(int) $credited['id']]);
$historyWithoutLiveRoom = Referral::forReferrer($referrerId);
check('historical property and room attribution survives a removed room reference',
    $historyWithoutLiveRoom[0]['reward_property_name'] === $property['name']
    && $historyWithoutLiveRoom[0]['reward_room_name'] === $room['name']);
$wallet = ReferralRedemption::walletForLead($referrerId);
check('room-level points appear in the referring tenant wallet', $wallet['earned_points'] === 175
    && $wallet['confirmed_referrals'] === 1);

$failureReferrerId = Lead::create([
    'wa_phone' => '601177770004',
    'name' => 'Delivery Failure Referrer',
    'source_channel' => 'whatsapp',
]);
$failureFriendId = Lead::create([
    'wa_phone' => '601177770005',
    'name' => 'Delivery Failure Friend',
    'source_channel' => 'referral',
]);
$failureCode = Referral::codeFor($failureReferrerId);
Referral::attachReferredLead($failureCode, $failureFriendId);
$failureBookingId = Booking::create([
    'lead_id' => $failureFriendId,
    'room_id' => $room['id'],
    'viewing_datetime' => '2026-08-16 14:00:00',
    'status' => 'confirmed',
]);
$messageFailed = false;
try {
    ConfirmationSender::send(
        Booking::find($failureBookingId),
        'mock-offline-stub',
        new class extends WhatsAppClient {
            public function sendText(string $toWaPhone, string $text): array
            {
                throw new RuntimeException('Simulated confirmation delivery failure.');
            }
        }
    );
} catch (RuntimeException) {
    $messageFailed = true;
}
$creditedDespiteFailure = Referral::findByCode($failureCode);
check('booking reward is durable before confirmation messaging can fail',
    $messageFailed
    && $creditedDespiteFailure['reward_status'] === 'credited'
    && (int) $creditedDespiteFailure['reward_points'] === 175);
