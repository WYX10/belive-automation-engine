<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Admin-tunable key/value settings (app_settings table), cached per request.
 * Reads never throw: a missing table or key falls back to the default so the
 * app still runs on an install that has not applied migration 030 yet.
 */
final class Settings
{
    /** @var array<string,?string>|null */
    private static ?array $cache = null;

    public static function get(string $key, string $default = ''): string
    {
        self::load();
        $value = self::$cache[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    public static function getInt(string $key, int $default): int
    {
        $value = self::get($key, (string) $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /** @return string[] */
    public static function getList(string $key, array $default): array
    {
        $value = self::get($key, '');
        if ($value === '') {
            return $default;
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    public static function set(string $key, string $value, ?string $by = null): void
    {
        // Warm the cache FIRST. Writing into a null cache would leave it
        // holding this one key and looking loaded, so every later get() in the
        // same request would skip the database and return its default.
        self::load();

        Database::run(
            'INSERT INTO app_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)',
            [$key, $value, $by]
        );
        self::$cache[$key] = $value;
    }

    private static function load(): void
    {
        if (self::$cache !== null) {
            return;
        }

        self::$cache = [];
        try {
            foreach (Database::run('SELECT setting_key, setting_value FROM app_settings')->fetchAll() as $row) {
                self::$cache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (\Throwable) {
            // Table not migrated yet — callers get their defaults.
        }
    }
}
