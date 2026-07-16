<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * Shared CRUD helpers so later phases read/write through models instead of
 * scattering raw SQL. Deliberately small — equality-map WHEREs cover the
 * app's needs; anything richer lives as a named method on the child model.
 */
abstract class BaseModel
{
    protected const TABLE = '';

    public static function find(int $id): ?array
    {
        $row = Database::run(
            'SELECT * FROM `' . static::TABLE . '` WHERE id = ? LIMIT 1',
            [$id]
        )->fetch();

        return $row ?: null;
    }

    /** @param array<string, mixed> $where column => value equality filters */
    public static function all(array $where = [], string $order = 'id DESC', int $limit = 0): array
    {
        [$clause, $params] = self::whereClause($where);
        $sql = 'SELECT * FROM `' . static::TABLE . '`' . $clause . ' ORDER BY ' . $order;
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }

        return Database::run($sql, $params)->fetchAll();
    }

    public static function first(array $where, string $order = 'id DESC'): ?array
    {
        $rows = static::all($where, $order, 1);
        return $rows[0] ?? null;
    }

    public static function count(array $where = []): int
    {
        [$clause, $params] = self::whereClause($where);
        return (int) Database::run(
            'SELECT COUNT(*) FROM `' . static::TABLE . '`' . $clause,
            $params
        )->fetchColumn();
    }

    /** @param array<string, mixed> $data column => value */
    public static function create(array $data): int
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO `' . static::TABLE . '` (`' . implode('`, `', $cols) . '`)'
             . ' VALUES (' . rtrim(str_repeat('?, ', count($cols)), ', ') . ')';
        Database::run($sql, array_values($data));

        return (int) Database::pdo()->lastInsertId();
    }

    public static function update(int $id, array $data): bool
    {
        if ($data === []) {
            return false;
        }
        $sets = implode(', ', array_map(fn ($c) => "`$c` = ?", array_keys($data)));
        $params = [...array_values($data), $id];

        return Database::run(
            'UPDATE `' . static::TABLE . '` SET ' . $sets . ' WHERE id = ?',
            $params
        )->rowCount() > 0;
    }

    protected static function db(): PDO
    {
        return Database::pdo();
    }

    /** @return array{0: string, 1: array} */
    private static function whereClause(array $where): array
    {
        if ($where === []) {
            return ['', []];
        }
        $parts = [];
        $params = [];
        foreach ($where as $col => $val) {
            if ($val === null) {
                $parts[] = "`$col` IS NULL";
            } else {
                $parts[] = "`$col` = ?";
                $params[] = $val;
            }
        }

        return [' WHERE ' . implode(' AND ', $parts), $params];
    }
}
