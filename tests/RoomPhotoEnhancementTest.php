<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\Room;
use App\Properties\PropertyManager;
use App\Properties\PropertyReviewManager;
use App\Properties\RoomPhotoEnhancer;
use App\Properties\RoomPhotoManager;

/**
 * The owner's phone shot: dark, and yellow from a tungsten bulb. What is
 * asserted here is that the touch-up fixes the *photograph* — brighter, cast
 * pulled out — while the owner's original file survives untouched on disk and
 * one click puts it back.
 */

$enhanceOwner = 'Photo Touch-up Owner';
$enhanceProperty = PropertyManager::addProperty($enhanceOwner, [
    'name' => 'Touch-up Residence',
    'location' => 'Setapak',
    'address' => '9 Touch-up Lane, Setapak',
]);
$enhanceProperty = PropertyReviewManager::review(
    (int) $enhanceProperty['id'],
    'approved',
    'photo-enhancement-admin',
    'Approved so photos can be managed.',
    (int) $enhanceProperty['review_version']
);
$enhanceRoom = PropertyManager::addRoom($enhanceOwner, [
    'property_id' => $enhanceProperty['id'],
    'room_code' => 'TUR-01',
    'name' => 'Badly Lit Master Room',
    'room_type' => 'master',
    'status' => 'available',
    'price_monthly' => '950',
    'price_6_month' => '900',
    'price_12_month' => '850',
    'referral_reward_points' => '80',
    'available_from' => '2026-09-01',
    'description' => 'Photographed at night under a yellow bulb.',
]);

// A room shot the way owners actually send them: underexposed and orange.
$canvas = imagecreatetruecolor(240, 160);
imagefilledrectangle($canvas, 0, 0, 239, 159, imagecolorallocate($canvas, 92, 68, 34));
imagefilledrectangle($canvas, 12, 20, 110, 140, imagecolorallocate($canvas, 122, 92, 48));
imagefilledrectangle($canvas, 130, 40, 228, 120, imagecolorallocate($canvas, 58, 42, 20));
imagefilledrectangle($canvas, 150, 55, 200, 95, imagecolorallocate($canvas, 140, 108, 58));
$sourceFile = tempnam(sys_get_temp_dir(), 'belive-dim-room-');
imagejpeg($canvas, $sourceFile, 92);
imagedestroy($canvas);

$before = RoomPhotoEnhancer::measure(imagecreatefromjpeg($sourceFile));
check('the test fixture really is a dark, warm-cast photo',
    $before['mean_luminance_pct'] < 40.0 && $before['colour_cast'] === 'warm',
    sprintf('luminance %.1f%%, cast %s', $before['mean_luminance_pct'], $before['colour_cast']));

$uploaded = RoomPhotoManager::addUpload(
    (int) $enhanceRoom['id'],
    [
        'name' => 'dim-room.jpg',
        'type' => 'image/jpeg',
        'tmp_name' => $sourceFile,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($sourceFile),
    ],
    null,
    static fn (string $from, string $to): bool => copy($from, $to)
);
$originalPath = $uploaded['image_path'];
$originalAbsolute = APP_ROOT . '/public' . $originalPath;
$originalBytes = file_get_contents($originalAbsolute);

$result = RoomPhotoEnhancer::enhance((int) $uploaded['id']);
$enhancedAbsolute = APP_ROOT . '/public' . $result['image_path'];

check('the touch-up writes a new file and leaves the owner\'s upload alone',
    $result['image_path'] !== $originalPath
    && is_file($enhancedAbsolute)
    && is_file($originalAbsolute)
    && file_get_contents($originalAbsolute) === $originalBytes);

check('the gallery now serves the corrected photo, with the original parked for revert',
    Room::photoUrls((int) $enhanceRoom['id']) === [$result['image_path']]
    && $result['original_path'] === $originalPath);

$after = RoomPhotoEnhancer::measure(imagecreatefromjpeg($enhancedAbsolute));
check('the corrected photo is brighter than the owner\'s upload',
    $after['mean_luminance_pct'] > $before['mean_luminance_pct'] + 4.0,
    sprintf('%.1f%% -> %.1f%%', $before['mean_luminance_pct'], $after['mean_luminance_pct']));
check('the tungsten colour cast is pulled back',
    $after['cast_strength_pct'] < $before['cast_strength_pct'],
    sprintf('%.1f%% -> %.1f%%', $before['cast_strength_pct'], $after['cast_strength_pct']));

$storedRow = Database::run('SELECT * FROM room_images WHERE id = ?', [(int) $uploaded['id']])->fetch();
check('the touch-up is recorded on the row so admin can see what was done and by which model',
    $storedRow['enhanced_at'] !== null
    && (string) $storedRow['enhanced_by_model'] !== ''
    && (string) $storedRow['enhance_note'] !== ''
    && is_array(json_decode((string) $storedRow['enhance_recipe'], true)['recipe'] ?? null));

check('the touch-up leaves an audit row naming the model',
    (int) Database::run(
        "SELECT COUNT(*) FROM ai_activity_log WHERE action = 'room_photo_enhanced'"
    )->fetchColumn() === 1);

// Re-running must go back to the untouched upload, never compound onto the
// previous result — two clicks would otherwise blow the photo out completely.
$second = RoomPhotoEnhancer::enhance((int) $uploaded['id']);
$secondAbsolute = APP_ROOT . '/public' . $second['image_path'];
$secondMeasure = RoomPhotoEnhancer::measure(imagecreatefromjpeg($secondAbsolute));
check('re-running the touch-up re-reads the original instead of compounding',
    $second['original_path'] === $originalPath
    && abs($secondMeasure['mean_luminance_pct'] - $after['mean_luminance_pct']) < 2.0,
    sprintf('%.1f%% vs %.1f%%', $after['mean_luminance_pct'], $secondMeasure['mean_luminance_pct']));
check('the superseded touch-up file is cleaned up', !is_file($enhancedAbsolute));

$reverted = RoomPhotoEnhancer::revert((int) $uploaded['id']);
$revertedRow = Database::run('SELECT * FROM room_images WHERE id = ?', [(int) $uploaded['id']])->fetch();
check('revert restores the owner\'s original photo byte for byte',
    $reverted['image_path'] === $originalPath
    && $revertedRow['original_path'] === null
    && $revertedRow['enhanced_at'] === null
    && $revertedRow['enhance_note'] === null
    && file_get_contents($originalAbsolute) === $originalBytes);
check('revert removes the generated file', !is_file($secondAbsolute));

$doubleRevertBlocked = false;
try {
    RoomPhotoEnhancer::revert((int) $uploaded['id']);
} catch (RuntimeException) {
    $doubleRevertBlocked = true;
}
check('an untouched photo cannot be reverted', $doubleRevertBlocked);

Database::run(
    'INSERT INTO room_images (room_id, image_path, sort_order) VALUES (?, ?, ?)',
    [(int) $enhanceRoom['id'], '/assets/img/uploads/videos/tour.mp4', 9]
);
$tourId = (int) Database::pdo()->lastInsertId();
$videoBlocked = false;
try {
    RoomPhotoEnhancer::enhance($tourId);
} catch (RuntimeException) {
    $videoBlocked = true;
}
check('a video tour is not treated as a photo to touch up', $videoBlocked);

$missingBlocked = false;
try {
    RoomPhotoEnhancer::enhance(0);
} catch (RuntimeException) {
    $missingBlocked = true;
}
check('touching up a photo that no longer exists fails cleanly', $missingBlocked);

@unlink($originalAbsolute);
@unlink($sourceFile);
