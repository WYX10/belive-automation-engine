<?php

/**
 * Database connection settings, sourced from .env only. Consumed by
 * App\Core\Database — nothing else should open its own connection.
 */

return [
    'host'    => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'port'    => (int)($_ENV['DB_PORT'] ?? 3306),
    'name'    => $_ENV['DB_NAME'] ?? 'belive_engine',
    'user'    => $_ENV['DB_USER'] ?? 'root',
    'pass'    => $_ENV['DB_PASS'] ?? '',
    'charset' => 'utf8mb4',
    'options' => [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ],
];
