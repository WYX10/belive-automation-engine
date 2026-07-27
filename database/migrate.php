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
// 39 joins the list because the house level hangs off properties (22).
$bonusMigrations = [11, 12, 13, 18, 19, 20, 21, 22, 23, 24, 25, 35, 39];

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
    $statements = array_values(array_filter(array_map('trim', explode(';', $sql))));

    // A migration is recorded only after its LAST statement succeeds, so a run
    // that dies half-way replays the whole file next time. Say exactly which
    // statement failed instead of dumping a stack trace at whoever is watching
    // the deploy — a raw "Duplicate column name" is a migration that is not
    // safe to re-run, not a mystery.
    foreach ($statements as $i => $statement) {
        try {
            $server->exec($statement);
        } catch (PDOException $e) {
            fwrite(STDERR, sprintf(
                "\nFAILED   %s — statement %d of %d\n  %s\n  %s\n\n"
                . "Nothing was recorded for this file, so the next run replays it from the top.\n"
                . "Make every statement in it safe to re-run before deploying again.\n",
                $name,
                $i + 1,
                count($statements),
                preg_replace('/\s+/', ' ', mb_substr($statement, 0, 160)),
                $e->getMessage()
            ));
            exit(1);
        }
    }

    $server->prepare('INSERT INTO migrations (filename) VALUES (?)')->execute([$name]);
    echo "applied  $name\n";
    $ran++;
}

echo $ran === 0 ? "Nothing to migrate — schema is current.\n" : "Done: $ran migration(s) applied.\n";
