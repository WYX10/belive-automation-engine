<?php

/**
 * Database connection settings, sourced from .env only. Consumed by
 * App\Core\Database — nothing else should open its own connection.
 */

$env = static function (string $name, string $default = ''): string {
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
    return $value === false ? $default : (string) $value;
};
$driver = $env('DB_DRIVER', 'mysql');
if (!in_array($driver, ['mysql', 'pgsql'], true)) {
    throw new InvalidArgumentException('DB_DRIVER must be mysql or pgsql.');
}

return [
    'driver'  => $driver,
    'host'    => $env('DB_HOST', '127.0.0.1'),
    'port'    => (int) $env('DB_PORT', $driver === 'pgsql' ? '5432' : '3306'),
    'name'    => $env('DB_NAME', $driver === 'pgsql' ? 'postgres' : 'belive_engine'),
    'user'    => $env('DB_USER', $driver === 'pgsql' ? 'postgres' : 'root'),
    'pass'    => $env('DB_PASS'),
    'schema'  => $env('DB_SCHEMA', 'belive'),
    'sslmode' => $env('DB_SSL_MODE', 'verify-full'),
    'sslrootcert' => $env('DB_SSL_ROOT_CERT', '/etc/ssl/certs/ca-certificates.crt'),
    'charset' => 'utf8mb4',
    'options' => [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ],
];
