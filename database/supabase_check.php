<?php

declare(strict_types=1);

/** Read-only hosted connection/schema check; credentials come from secure settings. */
if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(APP_ROOT)->safeLoad();

$cfg = require APP_ROOT . '/config/database.php';
$cfg['driver'] = 'pgsql';
$required = ['HOST', 'PORT', 'NAME', 'USER', 'PASS'];
foreach ($required as $suffix) {
    $name = 'SUPABASE_DB_' . $suffix;
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
    if ($value === false || $value === '') {
        fwrite(STDERR, "$name is missing. Enter it privately in environment settings.\n");
        exit(1);
    }
    $key = ['HOST' => 'host', 'PORT' => 'port', 'NAME' => 'name', 'USER' => 'user', 'PASS' => 'pass'][$suffix];
    $cfg[$key] = $suffix === 'PORT' ? (int) $value : (string) $value;
}
try {
    $pdo = App\Core\Database::connect($cfg);
    if (!$pdo->query('SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()')->fetchColumn()) {
        throw new RuntimeException('The hosted connection is not encrypted.');
    }
    echo "Supabase connection verified over TLS.\n";
    if (in_array('--require-import', $argv, true)) {
        $schema = App\Core\Database::identifier($cfg['schema']);
        $tables = ['leads', 'rooms', 'tenant_requirements', 'ai_feedback', 'ai_learned_memory', 'content_posts'];
        foreach ($tables as $table) {
            $count = (int) $pdo->query('SELECT COUNT(*) FROM ' . $schema . '.' . App\Core\Database::identifier($table))->fetchColumn();
            echo "$table: $count rows\n";
        }
        $stmt = $pdo->prepare('SELECT checksum FROM ' . $schema . '.postgres_migrations WHERE filename = ?');
        foreach (glob(APP_ROOT . '/database/postgres/migrations/*.sql') as $file) {
            $stmt->execute([basename($file)]);
            if (!hash_equals(hash_file('sha256', $file), (string) $stmt->fetchColumn())) {
                throw new RuntimeException('Imported schema does not match this application version: ' . basename($file));
            }
        }
        echo "Imported application schema verified.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Supabase check failed: " . $e->getMessage() . "\n");
    exit(1);
}
