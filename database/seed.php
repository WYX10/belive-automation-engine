<?php

declare(strict_types=1);

/**
 * Seed runner: loads database/seeds/demo_data.sql into the configured DB.
 *
 *   php database/seed.php          → refuses if leads/rooms already have data
 *   php database/seed.php --force  → seeds anyway (adds on top)
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(APP_ROOT)->safeLoad();

$cfg = require APP_ROOT . '/config/database.php';
$pdo = \App\Core\Database::pdo();

$existing = (int) $pdo->query('SELECT (SELECT COUNT(*) FROM rooms) + (SELECT COUNT(*) FROM leads)')->fetchColumn();
if ($existing > 0 && !in_array('--force', $argv, true)) {
    exit("Database already has rooms/leads ($existing rows). Re-run with --force to seed on top, or start from a fresh DB.\n");
}

$sql = file_get_contents(APP_ROOT . '/database/seeds/demo_data.sql');
$sql = preg_replace('/^\s*--.*$/m', '', $sql);

$count = 0;
foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
    $pdo->exec(\App\Core\Database::sql($statement));
    $count++;
}

echo "Seeded: $count statements executed.\n";
echo "Rooms: " . $pdo->query('SELECT COUNT(*) FROM rooms')->fetchColumn() . "\n";
echo "Leads: " . $pdo->query('SELECT COUNT(*) FROM leads')->fetchColumn() . "\n";
