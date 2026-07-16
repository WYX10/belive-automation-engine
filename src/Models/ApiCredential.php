<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Encryption;
use InvalidArgumentException;

/**
 * api_credentials — keys are AES-256-GCM encrypted at rest via Encryption
 * (key material in .env, never in DB, never in git). This class never returns
 * a decrypted key except through decryptedKeyFor()/decryptedKey(), called at
 * the moment of an actual API request. List views only ever see masked values.
 */
final class ApiCredential extends BaseModel
{
    protected const TABLE = 'api_credentials';

    /**
     * Store a key for a service. With $activate=true (default) it becomes the
     * single active key for that service; pass false to save it dormant so
     * test_connection can verify it before activation.
     */
    public static function store(string $service, string $label, string $plainKey, array $meta = [], bool $activate = true): int
    {
        if (!in_array($service, CREDENTIAL_SERVICES, true)) {
            throw new InvalidArgumentException("Unknown credential service: $service");
        }

        $id = self::create([
            'service'       => $service,
            'label'         => $label,
            'encrypted_key' => Encryption::encrypt($plainKey),
            'meta'          => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'is_active'     => 0,
        ]);

        if ($activate) {
            self::activate($id);
        }

        return $id;
    }

    /** Make this row the single active key for its service. */
    public static function activate(int $id): void
    {
        $row = self::find($id);
        if ($row === null) {
            return;
        }
        self::db()->prepare('UPDATE api_credentials SET is_active = 0 WHERE service = ?')
            ->execute([$row['service']]);
        self::update($id, ['is_active' => 1]);
    }

    public static function activeFor(string $service): ?array
    {
        $row = self::first(['service' => $service, 'is_active' => 1]);
        if ($row !== null) {
            unset($row['encrypted_key']); // callers never see key material
        }

        return $row;
    }

    /** Decrypt just-in-time for an outgoing API call. Null if not configured. */
    public static function decryptedKeyFor(string $service): ?string
    {
        $row = self::first(['service' => $service, 'is_active' => 1]);

        return $row === null ? null : Encryption::decrypt($row['encrypted_key']);
    }

    /** Decrypt a specific credential row (used by test_connection before activation). */
    public static function decryptedKey(int $id): ?string
    {
        $row = self::find($id);

        return $row === null ? null : Encryption::decrypt($row['encrypted_key']);
    }

    /** List rows with keys masked to their last 4 characters — for admin UI. */
    public static function maskedList(): array
    {
        $rows = self::all([], 'service ASC, is_active DESC, id DESC');

        return array_map(function (array $row) {
            $plain = '';
            try {
                $plain = Encryption::decrypt($row['encrypted_key']);
            } catch (\Throwable) {
                // Key encrypted under a different APP_ENCRYPTION_KEY — show as unreadable.
            }
            $row['masked_key'] = $plain === ''
                ? '(unreadable)'
                : str_repeat('•', 8) . substr($plain, -4);
            unset($row['encrypted_key']);

            return $row;
        }, $rows);
    }

    public static function recordTest(int $id, bool $ok, string $detail = ''): void
    {
        self::update($id, [
            'last_tested_at' => date('Y-m-d H:i:s'),
            'test_status'    => $ok ? 'ok' : 'failed',
            'test_detail'    => mb_substr($detail, 0, 500),
        ]);
    }
}
