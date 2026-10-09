<?php

declare(strict_types=1);

// Included by the CLI migration entrypoint. PostgreSQL already has a database
// (Supabase's postgres); only the private application schema is managed here.
$pdo = App\Core\Database::connect($cfg);
$schema = App\Core\Database::identifier($cfg['schema']);
$lock = 'belive_migrations:' . $cfg['schema'];
if (!App\Core\Database::acquireLock($lock, 10, $pdo)) {
    throw new RuntimeException('Another process is applying database migrations.');
}
try {
    $pdo->exec('CREATE SCHEMA IF NOT EXISTS ' . $schema);
    $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog');
    $pdo->exec('CREATE TABLE IF NOT EXISTS postgres_migrations (filename varchar(120) PRIMARY KEY, checksum char(64) NOT NULL, applied_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $applied = $pdo->query('SELECT filename, checksum FROM postgres_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
    $ran = 0;
    foreach (glob(__DIR__ . '/migrations/*.sql') as $file) {
        $name = basename($file);
        $checksum = hash_file('sha256', $file);
        if (isset($applied[$name])) {
            if (!hash_equals($checksum, $applied[$name])) {
                throw new RuntimeException('An applied PostgreSQL migration was edited: ' . $name);
            }
            continue;
        }
        $pdo->beginTransaction();
        try {
            $pdo->exec(file_get_contents($file));
            $pdo->prepare('INSERT INTO postgres_migrations (filename, checksum) VALUES (?, ?)')->execute([$name, $checksum]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw new RuntimeException('PostgreSQL migration failed: ' . $name, 0, $e);
        }
        echo "applied  $name\n";
        $ran++;
    }
    echo $ran ? "Done: $ran PostgreSQL migration(s) applied.\n" : "Nothing to migrate — PostgreSQL schema is current.\n";
} finally {
    App\Core\Database::releaseLock($lock, $pdo);
}
