<?php

declare(strict_types=1);

/**
 * Plain-PHP test runner (no PHPUnit dependency weight).
 *
 *   php tests/run.php
 *
 * Tests run against the real schema in a THROWAWAY database
 * (<DB_NAME>_test), emptied and migrated fresh on every run, then emptied
 * again — the development/demo data is never touched. The offline stub client is
 * forced on (deterministic, no API cost); what's asserted is the actual
 * pipeline logic: retrieval filtering, rule writing, confidence behaviour.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(APP_ROOT)->safeLoad();
require APP_ROOT . '/config/constants.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

// Tests always use the offline stub — deterministic and free.
$_ENV['MOCK_AI'] = 'true';

// ---- throwaway test database -------------------------------------------------
$cfg = require APP_ROOT . '/config/database.php';
$testDb = $cfg['name'] . '_test';

$server = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=%s', $cfg['host'], $cfg['port'], $cfg['charset']),
    $cfg['user'],
    $cfg['pass'],
    $cfg['options']
);
/**
 * Empty the test database by dropping its TABLES, never the database itself.
 *
 * DROP DATABASE immediately followed by CREATE DATABASE races on Windows:
 * MySQL removes the schema directory lazily while file handles are still open,
 * so the delayed removal can land midway through the migrations and take the
 * new tables with it ("Unknown database" three files in, then a half-built
 * schema). That produced failures in roughly half of all runs, scattered across
 * whichever test first touched a table that never got created.
 */
$wipe = static function (PDO $pdo, string $db): void {
    $tables = $pdo->query(
        "SELECT table_name FROM information_schema.tables WHERE table_schema = " . $pdo->quote($db)
    )->fetchAll(PDO::FETCH_COLUMN);

    if ($tables === []) {
        return;
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tables as $table) {
        $pdo->exec("DROP TABLE IF EXISTS `$db`.`$table`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
};

/**
 * Build the schema, and prove it was built. Neither half can be assumed on this
 * stack: CREATE DATABASE IF NOT EXISTS is a no-op when the server believes the
 * schema exists even though its directory has gone, and a DROP DATABASE can be
 * applied lazily enough to land midway through the migrations that follow it.
 * Either leaves a half-built schema, and the failure then surfaces in whichever
 * test first touches a table that was never created — nowhere near the cause.
 */
$migrate = static function (PDO $pdo) use ($testDb, $wipe): int {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$testDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$testDb`");
    $wipe($pdo, $testDb);

    foreach (glob(APP_ROOT . '/database/migrations/*.sql') as $file) {
        $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($file));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $pdo->exec($statement);
        }
    }

    return (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ' . $pdo->quote($testDb)
    )->fetchColumn();
};

$expectedTables = 0;
for ($attempt = 1; $attempt <= 3; $attempt++) {
    try {
        $expectedTables = $migrate($server);
        if ($expectedTables > 0) {
            break;
        }
    } catch (PDOException $e) {
        if ($attempt === 3) {
            throw $e;
        }
        // Force the schema to be rebuilt from nothing before trying again.
        $server->exec("DROP DATABASE IF EXISTS `$testDb`");
        usleep(500_000);
    }
}

if ($expectedTables === 0) {
    exit("Schema could not be built in {$testDb} after 3 attempts — check the MySQL data directory.\n");
}

App\Core\Database::swap($server);

// ---- micro-assert kit ----------------------------------------------------------
$GLOBALS['__results'] = [];

function check(string $name, bool $condition, string $detail = ''): void
{
    $GLOBALS['__results'][] = [$name, $condition, $detail];
    echo ($condition ? "  ✓ " : "  ✗ ") . $name . ($condition || $detail === '' ? '' : "  — $detail") . "\n";
}

// ---- run test files --------------------------------------------------------------
foreach (glob(__DIR__ . '/*Test.php') as $testFile) {
    echo "\n" . basename($testFile) . "\n";
    require $testFile;
}

// ---- summary + teardown -------------------------------------------------------------
$failed = array_filter($GLOBALS['__results'], fn ($r) => !$r[1]);
$total = count($GLOBALS['__results']);

// Leave the schema behind but empty, for the same reason it was not dropped at
// the start: the development/demo data is still untouched either way.
$wipe($server, $testDb);

echo "\n" . str_repeat('─', 46) . "\n";
echo sprintf("%d checks, %d passed, %d failed\n", $total, $total - count($failed), count($failed));
exit(count($failed) === 0 ? 0 : 1);
