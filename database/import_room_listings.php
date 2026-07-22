<?php

declare(strict_types=1);

/**
 * Imports the real BeLive room listing book (CSV) into properties / rooms /
 * room_pricing / room_amenities.
 *
 *   php database/import_room_listings.php path/to/room_listings.csv --dry-run
 *   php database/import_room_listings.php path/to/room_listings.csv
 *
 * Expected header:
 *   Condo Name, Price (RM), Address, Room Type, Bed Type, Bathroom Type, Parking Rental (RM)
 *
 * Idempotent: every row gets a deterministic room_code (BL-0001…) derived from
 * its position in the CSV, so a re-run updates in place instead of duplicating.
 * Re-running after editing the CSV mid-file will therefore rewrite the rooms
 * whose positions shifted — regenerate from a stable file, not an append.
 *
 * The CSV carries one price with no tenure. That price is taken as the monthly
 * (flexible) rate; 6-month is -5% and 12-month is -10%, rounded to RM10 and
 * flagged best value. Those discounts are a placeholder ladder — correct them
 * in room_pricing once the real tenure rates are agreed.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(APP_ROOT)->safeLoad();

$args   = array_values(array_filter(array_slice($argv, 1), static fn($a) => !str_starts_with($a, '--')));
$dryRun = in_array('--dry-run', $argv, true);
$csvPath = $args[0] ?? null;

if ($csvPath === null || !is_readable($csvPath)) {
    exit("Usage: php database/import_room_listings.php <room_listings.csv> [--dry-run]\n");
}

/** Owner is absent from the listing book; every condo is booked under BeLive. */
const OWNER_NAME = 'BeLive';

/**
 * CSV room labels → the rooms.room_type enum (migration 031). Modifier
 * variants ("With Big Window", "2nd", "Private") collapse to their base
 * product here; the nuance survives in rooms.name and room_amenities.
 */
function roomType(string $label): string
{
    $l = strtolower(trim($label));

    return match (true) {
        str_contains($l, 'studio')                      => 'studio',
        str_contains($l, 'mini single')                 => 'mini_single',
        str_contains($l, 'partition')                   => 'partitioned',
        str_contains($l, 'balcony')                     => 'balcony',
        str_contains($l, 'master')                      => 'master',
        str_contains($l, 'medium'), str_contains($l, 'middle') => 'middle',
        str_contains($l, 'single')                      => 'single',
        default                                         => 'single',
    };
}

/**
 * rooms.location wants the town, not the whole address. Malaysian addresses
 * put it right after the 5-digit postcode ("51200 Kuala Lumpur"); the messier
 * geocoded ones put the postcode last, with the town in the segment before it.
 */
function location(string $address): string
{
    if (preg_match('/\b\d{5}\s+([A-Za-z][A-Za-z\' ]+)/', $address, $m)) {
        return ucwords(strtolower(trim($m[1])));
    }

    $parts = array_map('trim', explode(',', $address));
    foreach ($parts as $i => $part) {
        if (preg_match('/^\d{5}$/', $part) && $i > 0) {
            return ucwords(strtolower($parts[$i - 1]));
        }
    }

    return ucwords(strtolower(end($parts) ?: 'Malaysia'));
}

/** Amenities in BeLive's ibilik vocabulary (see migration 017). */
function amenities(array $row): array
{
    $out = [];

    $bed = trim($row['Bed Type'] ?? '');
    if ($bed !== '') {
        $out[] = ucwords(strtolower($bed)) . ' Bed';
    }

    $bath = trim($row['Bathroom Type'] ?? '');
    if ($bath !== '') {
        $out[] = ucwords(strtolower($bath)) . ' Bathroom';
    }

    $parking = trim($row['Parking Rental (RM)'] ?? '');
    if ($parking !== '' && strtoupper($parking) !== 'N/A' && is_numeric($parking)) {
        $out[] = 'Parking (RM' . (int) $parking . '/month)';
    }

    $label = strtolower($row['Room Type'] ?? '');
    if (str_contains($label, 'big window')) {
        $out[] = 'Big Window';
    }
    if (str_contains($label, 'balcony')) {
        $out[] = 'Balcony';
    }

    return $out;
}

/** monthly → the three-tenure ladder room_pricing requires. */
function pricingLadder(float $monthly): array
{
    return [
        ['monthly',   $monthly,                            0],
        ['6_month',   round($monthly * 0.95 / 10) * 10,    0],
        ['12_month',  round($monthly * 0.90 / 10) * 10,    1],
    ];
}

// ---------------------------------------------------------------- read CSV

$handle = fopen($csvPath, 'r');
$header = fgetcsv($handle);
if ($header === false) {
    exit("Empty CSV.\n");
}
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);

$rows = [];
while (($line = fgetcsv($handle)) !== false) {
    if (count(array_filter($line, static fn($v) => trim((string) $v) !== '')) === 0) {
        continue; // trailing blank line
    }
    $rows[] = array_combine($header, array_pad($line, count($header), ''));
}
fclose($handle);

if ($rows === []) {
    exit("No data rows found in $csvPath.\n");
}

// Name rooms per property: "Master Room", and "Medium Room #2" when a property
// lists the same product twice (those are genuinely separate units).
$seen = [];
foreach ($rows as $i => $row) {
    $key = $row['Condo Name'] . '|' . $row['Room Type'];
    $seen[$key] = ($seen[$key] ?? 0) + 1;
    $rows[$i]['_seq'] = $seen[$key];
}
$totals = $seen;

// ---------------------------------------------------------------- import

$cfg = require APP_ROOT . '/config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset']),
    $cfg['user'],
    $cfg['pass'],
    $cfg['options']
);

$pdo->beginTransaction();

$propertyStmt = $pdo->prepare(
    'INSERT INTO properties (owner_name, name, location, address)
     VALUES (:owner, :name, :location, :address)
     ON DUPLICATE KEY UPDATE address = VALUES(address), id = LAST_INSERT_ID(id)'
);
$propertyFind = $pdo->prepare(
    'SELECT id FROM properties WHERE owner_name = ? AND name = ? AND location = ?'
);

$roomStmt = $pdo->prepare(
    'INSERT INTO rooms (property_id, room_code, name, property_name, location, room_type, owner_name, address, description, status)
     VALUES (:property_id, :room_code, :name, :property_name, :location, :room_type, :owner, :address, :description, \'available\')
     ON DUPLICATE KEY UPDATE
        property_id   = VALUES(property_id),
        name          = VALUES(name),
        property_name = VALUES(property_name),
        location      = VALUES(location),
        room_type     = VALUES(room_type),
        address       = VALUES(address),
        description   = VALUES(description),
        id            = LAST_INSERT_ID(id)'
);

$priceStmt = $pdo->prepare(
    'INSERT INTO room_pricing (room_id, tenure, price, is_best_value)
     VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE price = VALUES(price), is_best_value = VALUES(is_best_value)'
);
$amenityWipe = $pdo->prepare('DELETE FROM room_amenities WHERE room_id = ?');
$amenityStmt = $pdo->prepare('INSERT INTO room_amenities (room_id, amenity) VALUES (?, ?)');

$properties = [];
$roomCount = 0;

foreach ($rows as $i => $row) {
    $condo    = trim($row['Condo Name']);
    $address  = trim($row['Address']);
    $location = location($address);
    $type     = roomType($row['Room Type']);
    $monthly  = (float) preg_replace('/[^\d.]/', '', $row['Price (RM)']);

    if ($condo === '' || $monthly <= 0) {
        echo "skip row " . ($i + 2) . ": missing condo name or price\n";
        continue;
    }

    $propKey = $condo . '|' . $location;
    if (!isset($properties[$propKey])) {
        $propertyStmt->execute([
            ':owner'    => OWNER_NAME,
            ':name'     => $condo,
            ':location' => $location,
            ':address'  => $address,
        ]);
        $propertyId = (int) $pdo->lastInsertId();
        if ($propertyId === 0) {
            $propertyFind->execute([OWNER_NAME, $condo, $location]);
            $propertyId = (int) $propertyFind->fetchColumn();
        }
        $properties[$propKey] = $propertyId;
    }

    $label = ucwords(strtolower(trim($row['Room Type'])));
    $name  = $totals[$condo . '|' . $row['Room Type']] > 1
        ? sprintf('%s Room #%d', $label, $row['_seq'])
        : $label . ' Room';

    $bath        = strtolower(trim($row['Bathroom Type'] ?? ''));
    $description = sprintf(
        '%s at %s. %s bathroom, %s bed.',
        $label,
        $condo,
        ucfirst($bath ?: 'shared'),
        strtolower(trim($row['Bed Type'] ?? 'single'))
    );

    $roomCode = sprintf('BL-%04d', $i + 1);

    $roomStmt->execute([
        ':property_id'   => $properties[$propKey],
        ':room_code'     => $roomCode,
        ':name'          => $name,
        ':property_name' => $condo,
        ':location'      => $location,
        ':room_type'     => $type,
        ':owner'         => OWNER_NAME,
        ':address'       => $address,
        ':description'   => $description,
    ]);
    $roomId = (int) $pdo->lastInsertId();

    foreach (pricingLadder($monthly) as [$tenure, $price, $best]) {
        $priceStmt->execute([$roomId, $tenure, $price, $best]);
    }

    $amenityWipe->execute([$roomId]);
    foreach (amenities($row) as $amenity) {
        $amenityStmt->execute([$roomId, $amenity]);
    }

    $roomCount++;
}

if ($dryRun) {
    $pdo->rollBack();
    echo "DRY RUN — rolled back.\n";
} else {
    $pdo->commit();
}

printf("Properties: %d\nRooms: %d\nPricing rows: %d\n", count($properties), $roomCount, $roomCount * 3);
printf(
    "\nTotals now in DB — properties: %d, rooms: %d, pricing: %d, amenities: %d\n",
    $pdo->query('SELECT COUNT(*) FROM properties')->fetchColumn(),
    $pdo->query('SELECT COUNT(*) FROM rooms')->fetchColumn(),
    $pdo->query('SELECT COUNT(*) FROM room_pricing')->fetchColumn(),
    $pdo->query('SELECT COUNT(*) FROM room_amenities')->fetchColumn()
);
