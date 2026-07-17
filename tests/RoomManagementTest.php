<?php

declare(strict_types=1);

use App\Models\Property;
use App\Models\Room;
use App\Properties\PropertyManager;
use App\Properties\PropertyReviewManager;
use App\Properties\RoomPhotoManager;

$roomOwner = 'Admin Room Management Owner';
$managedProperty = PropertyManager::addProperty($roomOwner, [
    'name' => 'Managed Room Residence',
    'location' => 'Wangsa Maju',
    'address' => '18 Management Road, Wangsa Maju',
]);

$baseRoomInput = [
    'room_code' => 'MRR-01',
    'name' => 'Owner Uploaded Room',
    'room_type' => 'middle',
    'status' => 'available',
    'price_monthly' => '900',
    'price_6_month' => '850',
    'price_12_month' => '800',
    'referral_reward_points' => '80',
    'available_from' => '2026-09-01',
    'description' => 'Created by owner before admin management.',
];

$adminPendingAddBlocked = false;
try {
    PropertyManager::addRoomForAdmin((int) $managedProperty['id'], $baseRoomInput);
} catch (RuntimeException) {
    $adminPendingAddBlocked = true;
}
check('admin cannot add a room before property approval', $adminPendingAddBlocked);

$managedProperty = PropertyReviewManager::review(
    (int) $managedProperty['id'],
    'approved',
    'room-management-admin',
    'Property confirmed for room management.',
    (int) $managedProperty['review_version']
);
$ownerRoom = PropertyManager::addRoom(
    $roomOwner,
    array_replace($baseRoomInput, ['property_id' => $managedProperty['id']])
);
check('owner can upload the room record after approval', $ownerRoom['owner_name'] === $roomOwner);

$updatedRoom = PropertyManager::updateRoomForAdmin((int) $ownerRoom['id'], [
    'room_code' => 'MRR-01',
    'name' => 'Admin Managed Owner Room',
    'room_type' => 'master',
    'status' => 'reserved',
    'price_monthly' => '1100',
    'price_6_month' => '1025',
    'price_12_month' => '950',
    'referral_reward_points' => '140',
    'available_from' => '2026-10-01',
    'description' => 'Updated by admin while preserving owner and property.',
]);
$updatedPrices = Room::prices((int) $ownerRoom['id']);
check('admin can manage a room uploaded by the owner',
    $updatedRoom['name'] === 'Admin Managed Owner Room'
    && $updatedRoom['status'] === 'reserved'
    && (int) $updatedRoom['referral_reward_points'] === 140
    && $updatedRoom['owner_name'] === $roomOwner
    && (int) $updatedRoom['property_id'] === (int) $managedProperty['id']
    && $updatedPrices['monthly']['price'] === 1100.0
    && $updatedPrices['12_month']['price'] === 950.0);

$adminRoom = PropertyManager::addRoomForAdmin((int) $managedProperty['id'], array_replace($baseRoomInput, [
    'room_code' => 'MRR-02',
    'name' => 'Admin Added Room',
]));
check('admin can add a room to an approved owner property',
    $adminRoom['owner_name'] === $roomOwner
    && (int) $adminRoom['property_id'] === (int) $managedProperty['id']);

$pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
$validPhoto = tempnam(sys_get_temp_dir(), 'belive-room-photo-');
file_put_contents($validPhoto, $pngBytes);
$file = [
    'name' => 'room.png',
    'type' => 'image/png',
    'tmp_name' => $validPhoto,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($validPhoto),
];
$copyMover = static fn (string $from, string $to): bool => copy($from, $to);

$ownerPhoto = RoomPhotoManager::addUpload((int) $ownerRoom['id'], $file, $roomOwner, $copyMover);
$ownerPhotoAbsolute = APP_ROOT . '/public' . $ownerPhoto['image_path'];
check('owner room photo is securely stored and added to the gallery',
    is_file($ownerPhotoAbsolute)
    && Room::photoUrls((int) $ownerRoom['id']) === [$ownerPhoto['image_path']]);

$crossOwnerPhotoBlocked = false;
try {
    RoomPhotoManager::addUpload((int) $ownerRoom['id'], $file, 'Different Room Owner', $copyMover);
} catch (RuntimeException) {
    $crossOwnerPhotoBlocked = true;
}
check('another owner cannot upload a photo to this room', $crossOwnerPhotoBlocked);

$adminPhoto = RoomPhotoManager::addUpload((int) $adminRoom['id'], $file, null, $copyMover);
$adminPhotoAbsolute = APP_ROOT . '/public' . $adminPhoto['image_path'];
check('admin can upload a photo for an owner room',
    is_file($adminPhotoAbsolute)
    && $adminPhoto['sort_order'] === 0);

$invalidPhoto = tempnam(sys_get_temp_dir(), 'belive-invalid-photo-');
file_put_contents($invalidPhoto, 'not an image');
$invalidUploadBlocked = false;
try {
    RoomPhotoManager::addUpload((int) $adminRoom['id'], [
        'name' => 'fake.jpg',
        'type' => 'image/jpeg',
        'tmp_name' => $invalidPhoto,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($invalidPhoto),
    ], null, $copyMover);
} catch (RuntimeException) {
    $invalidUploadBlocked = true;
}
check('file extension and browser MIME cannot disguise a non-image upload', $invalidUploadBlocked);

@unlink($ownerPhotoAbsolute);
@unlink($adminPhotoAbsolute);
@unlink($validPhoto);
@unlink($invalidPhoto);

check('admin room management does not change property approval',
    Property::find((int) $managedProperty['id'])['review_status'] === 'approved');
