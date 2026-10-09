<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Single shared PDO connection, configured from config/database.php (which
 * reads .env). Nothing else in the app opens its own connection.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $cfg = require APP_ROOT . '/config/database.php';
            self::$pdo = self::connect($cfg);
        }

        return self::$pdo;
    }

    /** Prepared-statement shorthand used across models. */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare(self::sql($sql));
        $stmt->execute($params);
        return $stmt;
    }

    public static function connect(array $cfg): PDO
    {
        $driver = $cfg['driver'] ?? 'mysql';
        foreach (['host', 'name', 'user', 'sslmode', 'sslrootcert'] as $key) {
            if (isset($cfg[$key]) && preg_match('/[;\r\n]/', (string) $cfg[$key])) {
                throw new \InvalidArgumentException('Invalid database connection setting: ' . $key);
            }
        }
        if ($driver === 'mysql') {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset']);
        } elseif ($driver === 'pgsql') {
            $mode = $cfg['sslmode'] ?? 'verify-full';
            if (!in_array($mode, ['disable', 'require', 'verify-ca', 'verify-full'], true)) {
                throw new \InvalidArgumentException('Invalid DB_SSL_MODE.');
            }
            if ($mode === 'disable' && !in_array($cfg['host'], ['127.0.0.1', 'localhost', '::1'], true)) {
                throw new \InvalidArgumentException('TLS can only be disabled for a local PostgreSQL database.');
            }
            $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;connect_timeout=10', $cfg['host'], $cfg['port'], $cfg['name'], $mode);
            if (!empty($cfg['sslrootcert']) && in_array($mode, ['verify-ca', 'verify-full'], true)) {
                $dsn .= ';sslrootcert=' . $cfg['sslrootcert'];
            }
        } else {
            throw new \InvalidArgumentException('Unsupported database driver.');
        }
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $cfg['options']);
        if ($driver === 'pgsql') {
            $pdo->exec('SET search_path TO ' . self::identifier($cfg['schema'] ?? 'belive') . ', pg_catalog');
            $pdo->exec("SET TIME ZONE 'UTC'");
        }
        return $pdo;
    }

    public static function isPostgres(): bool
    {
        return self::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }

    /** Convert only the legacy SQL constructs used by this app; never parameter values. */
    public static function sql(string $sql): string
    {
        return self::isPostgres() ? PostgresSql::convert($sql) : $sql;
    }

    /** RETURNING preserves the existing ID on a PostgreSQL upsert. */
    public static function insert(string $sql, array $params): int
    {
        if (self::isPostgres()) {
            $stmt = self::pdo()->prepare(self::sql($sql) . ' RETURNING id');
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        }
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    public static function identifier(string $name): string
    {
        if (!preg_match('/^[a-z_][a-z0-9_]{0,62}$/D', $name)) {
            throw new \InvalidArgumentException('Invalid PostgreSQL identifier.');
        }
        return '"' . $name . '"';
    }

    /** Session locks require the Supabase session pooler, not transaction pooling. */
    public static function acquireLock(string $name, int $timeout = 0, ?PDO $pdo = null): bool
    {
        $pdo ??= self::pdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
            $stmt->execute([$name, max(0, $timeout)]);
            return (int) $stmt->fetchColumn() === 1;
        }
        $deadline = hrtime(true) + max(0, $timeout) * 1_000_000_000;
        $stmt = $pdo->prepare('SELECT pg_try_advisory_lock(hashtextextended(CAST(? AS text), 0))');
        do {
            $stmt->execute([$name]);
            if ($stmt->fetchColumn()) {
                return true;
            }
            if (hrtime(true) >= $deadline) {
                return false;
            }
            usleep(40_000);
        } while (true);
    }

    public static function releaseLock(string $name, ?PDO $pdo = null): void
    {
        $pdo ??= self::pdo();
        $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
            ? 'SELECT pg_advisory_unlock(hashtextextended(CAST(? AS text), 0))'
            : 'SELECT RELEASE_LOCK(?)';
        $pdo->prepare($sql)->execute([$name]);
    }

    /** For tests: point the app at an alternate connection (e.g. a test DB). */
    public static function swap(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }
}
