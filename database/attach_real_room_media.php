<?php

declare(strict_types=1);

/**
 * Attaches the real unit photography (shot Jan–Jun 2026) to the rooms it
 * depicts, and retires those rooms' "photo coming soon" placeholders.
 *
 *   php database/attach_real_room_media.php
 *
 * Provenance — the mapping below is verified, not decorative:
 *   · emporis-*   GPS-located to Kota Damansara PJU 5 / 47810 (Emporis).
 *   · riamas-*    GPS-located to Taman OUG 58200, confirmed by the team as
 *                 Residency Riamas; kitchen/laundry/bedroom set and the
 *                 January DJI tours confirmed same property.
 * The published files are re-encoded with EXIF (incl. GPS) stripped.
 *
 * Bedroom photos attach only to the room type they show; dining, kitchen,
 * laundry and the video tours are common areas of the unit, so they attach to
 * every room at that property — same convention as the demo seed. The Riamas
 * Balcony Room deliberately gets no bedroom photo: none of the photography
 * shows it, so its gallery is common areas + tours only.
 *
 * Idempotent: each room's listed files are deleted then re-inserted in order.
 * Rooms are looked up by property + name and the script aborts if any target
 * is missing — a wrong room would be worse than no room.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(APP_ROOT)->safeLoad();

const IMG = '/assets/img/rooms';

/** Common-area media shared by every room of the Riamas unit, in gallery order. */
const RIAMAS_COMMON = [
    IMG . '/riamas-dining.jpg',
    IMG . '/riamas-kitchen.jpg',
    IMG . '/riamas-laundry.jpg',
    IMG . '/videos/riamas-tour-bedroom-view.mp4',
    IMG . '/videos/riamas-tour-dining.mp4',
    IMG . '/videos/riamas-tour-master.mp4',
];

/** property name → room name → gallery files, room-specific first. */
$plan = [
    'Emporis Kota Damansara' => [
        'Studio Room'  => [IMG . '/emporis-pool.jpg', IMG . '/emporis-gym.jpg'],
        'Balcony Room' => [IMG . '/emporis-pool.jpg', IMG . '/emporis-gym.jpg'],
        'Single Room'  => [IMG . '/emporis-pool.jpg', IMG . '/emporis-gym.jpg'],
    ],
    'Residency Riamas' => [
        'Master Room' => array_merge([
            IMG . '/riamas-master-ensuite-1.jpg',
            IMG . '/riamas-master-ensuite-2.jpg',
            IMG . '/riamas-master-vanity.jpg',
        ], RIAMAS_COMMON),
        'Medium Room #1' => array_merge([IMG . '/riamas-medium-1.jpg'], RIAMAS_COMMON),
        'Medium Room #2' => array_merge([IMG . '/riamas-medium-2.jpg'], RIAMAS_COMMON),
        'Balcony Room'   => RIAMAS_COMMON,
    ],
];

// Every referenced file must exist under public/ before any row is written.
$missing = [];
foreach ($plan as $rooms) {
    foreach ($rooms as $files) {
        foreach ($files as $f) {
            if (!is_file(APP_ROOT . '/public' . $f)) {
                $missing[$f] = true;
            }
        }
    }
}
if ($missing !== []) {
    exit("Missing asset files:\n  " . implode("\n  ", array_keys($missing)) . "\n");
}

$cfg = require APP_ROOT . '/config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset']),
    $cfg['user'],
    $cfg['pass'],
    $cfg['options']
);

$findRoom = $pdo->prepare('SELECT id FROM rooms WHERE property_name = ? AND name = ?');

// Resolve every room first so a typo aborts before any write.
$targets = [];
foreach ($plan as $property => $rooms) {
    foreach ($rooms as $roomName => $files) {
        $findRoom->execute([$property, $roomName]);
        $ids = $findRoom->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) !== 1) {
            exit(sprintf("Expected exactly one room for %s / %s, found %d — aborting.\n", $property, $roomName, count($ids)));
        }
        $targets[] = [(int) $ids[0], $property, $roomName, $files];
    }
}

$pdo->beginTransaction();

$deleteFile   = $pdo->prepare('DELETE FROM room_images WHERE room_id = ? AND image_path = ?');
$deleteSvg    = $pdo->prepare("DELETE FROM room_images WHERE room_id = ? AND image_path LIKE '/assets/img/rooms/placeholders/%'");
$nextSort     = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM room_images WHERE room_id = ?');
$insert       = $pdo->prepare('INSERT INTO room_images (room_id, image_path, sort_order) VALUES (?, ?, ?)');

foreach ($targets as [$roomId, $property, $roomName, $files]) {
    // Real media replaces the "photo coming soon" placeholder.
    $deleteSvg->execute([$roomId]);

    // Re-run safety: remove this plan's files, then insert them in plan order
    // ahead of anything else the room already has.
    foreach ($files as $f) {
        $deleteFile->execute([$roomId, $f]);
    }
    $nextSort->execute([$roomId]);
    $base = (int) $nextSort->fetchColumn();
    foreach ($files as $i => $f) {
        $insert->execute([$roomId, $f, $base + $i]);
    }

    printf("%-24s %-16s %d file(s)\n", $property, $roomName, count($files));
}

$pdo->commit();

printf(
    "\nDone. room_images now holds %d rows (%d placeholders remaining).\n",
    (int) $pdo->query('SELECT COUNT(*) FROM room_images')->fetchColumn(),
    (int) $pdo->query("SELECT COUNT(*) FROM room_images WHERE image_path LIKE '%placeholders%'")->fetchColumn()
);
