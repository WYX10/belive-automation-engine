<?php

declare(strict_types=1);

/**
 * Give a room an owner, on whichever database .env points at.
 *
 *   php database/assign_room_owner.php RM-111 "Encik Rahman"
 *   php database/assign_room_owner.php RM-111 "Ms Tan Li Hua" --force
 *
 * A room with no owner_name cannot travel through the agreement signing
 * workflow: AgreementWorkflow::sendToOwner refuses it and the admin view shows
 * "No owner on the room". Three things have to line up, so this does all three:
 *
 *   1. the properties row for that owner + property + location (approved —
 *      PropertyManager and RoomPhotoManager both gate on approved),
 *   2. rooms.owner_name and rooms.property_id,
 *   3. any DRAFT agreement for the room, which snapshotted owner_name when it
 *      was generated and would otherwise keep showing the old empty value.
 *
 * Re-running changes nothing. Reassigning a room that already has a different
 * owner needs --force, so a typo can't quietly move someone else's listing.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(APP_ROOT)->safeLoad();

use App\Core\Database;

$args     = array_values(array_filter(array_slice($argv, 1), static fn ($a) => $a !== '--force'));
$force    = in_array('--force', $argv, true);
$roomCode = trim($args[0] ?? '');
$owner    = trim($args[1] ?? '');

if ($roomCode === '' || $owner === '') {
    exit("Usage: php database/assign_room_owner.php <room_code> \"<owner name>\" [--force]\n");
}

$cfg = require APP_ROOT . '/config/database.php';
echo "Database: {$cfg['name']} on {$cfg['host']}\n\n";

$room = Database::run('SELECT * FROM rooms WHERE room_code = ?', [$roomCode])->fetch();
if ($room === false) {
    exit("No room with code $roomCode.\n");
}

$current = (string) ($room['owner_name'] ?? '');
if ($current !== '' && $current !== $owner && !$force) {
    exit("$roomCode already belongs to $current. Re-run with --force to reassign it to $owner.\n");
}

printf(
    "%s · %s · %s — owner: %s\n",
    $roomCode,
    $room['name'],
    $room['location'],
    $current !== '' ? $current : 'none'
);

$property = $room['property_name'] !== '' && $room['property_name'] !== null
    ? $room['property_name']
    : $room['name'];
$address = $room['address'] !== '' && $room['address'] !== null
    ? $room['address']
    : 'Address to be confirmed';

$pdo = Database::pdo();
$pdo->beginTransaction();

try {
    Database::run(
        "INSERT INTO properties (owner_name, name, location, address, review_status, reviewed_by, reviewed_at)
         VALUES (?, ?, ?, ?, 'approved', 'admin', NOW())
         ON DUPLICATE KEY UPDATE address = VALUES(address)",
        [$owner, $property, $room['location'], $address]
    );

    $propertyId = (int) Database::run(
        'SELECT id FROM properties WHERE owner_name = ? AND name = ? AND location = ?',
        [$owner, $property, $room['location']]
    )->fetchColumn();

    Database::run(
        'UPDATE rooms SET owner_name = ?, property_id = ? WHERE id = ?',
        [$owner, $propertyId, (int) $room['id']]
    );

    $agreements = Database::run(
        "UPDATE digital_agreements
         SET owner_name = ?
         WHERE room_id = ? AND status = 'draft' AND (owner_name IS NULL OR owner_name <> ?)",
        [$owner, (int) $room['id'], $owner]
    )->rowCount();

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

echo "\nDone.\n";
echo "  owner       $owner\n";
echo "  property    #$propertyId $property, {$room['location']} (approved)\n";
echo "  agreements  $agreements draft(s) rebound to the new owner\n";
