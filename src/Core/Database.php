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
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $cfg['host'],
                $cfg['port'],
                $cfg['name'],
                $cfg['charset']
            );
            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $cfg['options']);
        }

        return self::$pdo;
    }

    /** Prepared-statement shorthand used across models. */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** For tests: point the app at an alternate connection (e.g. a test DB). */
    public static function swap(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }
}
