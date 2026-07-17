<?php

declare(strict_types=1);

/**
 * CLI migration runner.
 *
 *   php database/migrate.php               → creates the database if needed, runs ALL migrations
 *   php database/migrate.php --core-only   → skip optional portal migrations listed below
 *
 * The Phase 9 portal tables run by default: the tenant/owner portals are
 * linked from the public site nav, so their tables are required for a
 * complete install. Applied migrations are tracked in a `migrations` table;
 * re-running is safe.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(APP_ROOT)->safeLoad();

$cfg = require APP_ROOT . '/config/database.php';
$includeBonus = !in_array('--core-only', $argv, true);
$bonusMigrations = [11, 12, 13, 18, 19, 20, 21, 22, 23, 24, 25];

// Connect server-level first so we can create the database itself.
$server = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=%s', $cfg['host'], $cfg['port'], $cfg['charset']),
    $cfg['user'],
    $cfg['pass'],
    $cfg['options']
);
$server->exec(sprintf(
    'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    $cfg['name']
));
$server->exec(sprintf('USE `%s`', $cfg['name']));

$server->exec(
    'CREATE TABLE IF NOT EXISTS migrations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(120) NOT NULL UNIQUE,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB'
);

$applied = $server->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

$files = glob(__DIR__ . '/migrations/*.sql');
sort($files, SORT_NATURAL);

$ran = 0;
foreach ($files as $file) {
    $name = basename($file);
    $number = (int) substr($name, 0, 3);

    if (!$includeBonus && in_array($number, $bonusMigrations, true)) {
        continue; // --core-only: skip optional portal tables and their extensions
    }
    if (in_array($name, $applied, true)) {
        continue;
    }

    // Strip -- comments, split into individual statements.
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($file));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $server->exec($statement);
    }

    $server->prepare('INSERT INTO migrations (filename) VALUES (?)')->execute([$name]);
    echo "applied  $name\n";
    $ran++;
}

echo $ran === 0 ? "Nothing to migrate — schema is current.\n" : "Done: $ran migration(s) applied.\n";
