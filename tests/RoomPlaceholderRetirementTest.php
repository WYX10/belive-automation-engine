<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\Room;
use App\Properties\PropertyManager;
use App\Properties\PropertyReviewManager;
use App\Properties\RoomPhotoEnhancer;
use App\Properties\RoomPhotoManager;

/**
 * A photoless room carries the generated "photo coming soon" line art at
 * sort_order 0. What is asserted here is that uploading a real photograph
 * retires it: the placeholder was the room's cover everywhere photoUrls()[0]
 * is read — public cards, social drafts, WhatsApp sends — so leaving it in
 * place meant an admin could upload a photo and still see "photo coming soon"
 * on the live listing, with no way to remove it from the UI.
 */

$phOwner = 'Placeholder Retirement Owner';
$phProperty = PropertyManager::addProperty($phOwner, [
    'name' => 'Placeholder Court',
    'location' => 'Cheras',
    'address' => '4 Placeholder Road, Cheras',
]);
$phProperty = PropertyReviewManager::review(
    (int) $phProperty['id'],
    'approved',
    'placeholder-admin',
    'Approved so photos can be managed.',
    (int) $phProperty['review_version']
);
$phRoom = PropertyManager::addRoom($phOwner, [
    'property_id' => $phProperty['id'],
    'room_code' => 'PLC-01',
    'name' => 'Not Yet Photographed Room',
    'room_type' => 'single',
    'status' => 'available',
    'price_monthly' => '700',
    'price_6_month' => '670',
    'price_12_month' => '630',
    'referral_reward_points' => '40',
    'available_from' => '2026-09-01',
    'description' => 'Nobody has photographed this one yet.',
]);
$phRoomId = (int) $phRoom['id'];

// Exactly what generate_room_placeholders.php --attach writes.
const PLACEHOLDER_PATH = '/assets/img/rooms/placeholders/single.svg';
Database::run(
    'INSERT INTO room_images (room_id, image_path, sort_order) VALUES (?, ?, 0)',
    [$phRoomId, PLACEHOLDER_PATH]
);

check('a photoless room shows the placeholder as its cover',
    (Room::photoUrls($phRoomId)[0] ?? null) === PLACEHOLDER_PATH);

// The AI touch-up has no photograph to correct here, and GD cannot decode SVG.
$placeholderId = (int) Database::run(
    'SELECT id FROM room_images WHERE room_id = ? AND image_path = ?',
    [$phRoomId, PLACEHOLDER_PATH]
)->fetchColumn();
$placeholderBlocked = false;
try {
    RoomPhotoEnhancer::enhance($placeholderId);
} catch (RuntimeException) {
    $placeholderBlocked = true;
}
check('the placeholder is refused for AI touch-up rather than failing on disk', $placeholderBlocked);

// A real photograph arrives through the admin/owner upload flow.
$shot = imagecreatetruecolor(200, 140);
imagefilledrectangle($shot, 0, 0, 199, 139, imagecolorallocate($shot, 208, 202, 194));
imagefilledrectangle($shot, 18, 40, 96, 118, imagecolorallocate($shot, 120, 132, 150));
$shotFile = tempnam(sys_get_temp_dir(), 'belive-real-room-');
imagejpeg($shot, $shotFile, 90);
imagedestroy($shot);

$realPhoto = RoomPhotoManager::addUpload(
    $phRoomId,
    [
        'name' => 'real-room.jpg',
        'type' => 'image/jpeg',
        'tmp_name' => $shotFile,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($shotFile),
    ],
    null,
    static fn (string $from, string $to): bool => copy($from, $to)
);

$remainingPlaceholders = (int) Database::run(
    "SELECT COUNT(*) FROM room_images WHERE room_id = ? AND image_path LIKE '/assets/img/rooms/placeholders/%'",
    [$phRoomId]
)->fetchColumn();
check('uploading a real photo retires the placeholder', $remainingPlaceholders === 0,
    "placeholder rows left: $remainingPlaceholders");

$cover = Room::photoUrls($phRoomId)[0] ?? null;
check('the uploaded photograph is now the room cover', $cover === $realPhoto['image_path'],
    'cover is ' . var_export($cover, true));

check('the room has exactly one image — the real one',
    count(Room::photoUrls($phRoomId)) === 1);

// A second upload is an addition, not a replacement: only placeholders retire.
$second = RoomPhotoManager::addUpload(
    $phRoomId,
    [
        'name' => 'real-room-2.jpg',
        'type' => 'image/jpeg',
        'tmp_name' => $shotFile,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($shotFile),
    ],
    null,
    static fn (string $from, string $to): bool => copy($from, $to)
);
check('a second upload adds to the gallery without displacing the first',
    count(Room::photoUrls($phRoomId)) === 2
    && (Room::photoUrls($phRoomId)[0] ?? null) === $realPhoto['image_path']);

@unlink(APP_ROOT . '/public' . $realPhoto['image_path']);
@unlink(APP_ROOT . '/public' . $second['image_path']);
@unlink($shotFile);
