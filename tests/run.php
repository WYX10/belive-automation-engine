<?php

declare(strict_types=1);

/**
 * Plain-PHP test runner (no PHPUnit dependency weight).
 *
 *   php tests/run.php
 *
 * Tests run against the real schema in a THROWAWAY database
 * (<DB_NAME>_test), created and migrated fresh on every run, then dropped —
 * the development/demo data is never touched. The offline stub client is
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
$server->exec("DROP DATABASE IF EXISTS `$testDb`");
$server->exec("CREATE DATABASE `$testDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$server->exec("USE `$testDb`");

foreach (glob(APP_ROOT . '/database/migrations/*.sql') as $file) {
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($file));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $server->exec($statement);
    }
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

$server->exec("DROP DATABASE IF EXISTS `$testDb`");

echo "\n" . str_repeat('─', 46) . "\n";
echo sprintf("%d checks, %d passed, %d failed\n", $total, $total - count($failed), count($failed));
exit(count($failed) === 0 ? 0 : 1);
