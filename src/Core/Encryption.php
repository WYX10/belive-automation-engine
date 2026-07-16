<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * AES-256-GCM encrypt/decrypt for API credentials at rest. The key lives in
 * .env (APP_ENCRYPTION_KEY, base64 of 32 random bytes) — never in the
 * database, never in git. Output format: base64(iv . tag . ciphertext).
 */
final class Encryption
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LEN = 12;
    private const TAG_LEN = 16;

    public static function encrypt(string $plaintext): string
    {
        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN);

        if ($cipher === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $blob): string
    {
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) <= self::IV_LEN + self::TAG_LEN) {
            throw new RuntimeException('Malformed encrypted value.');
        }

        $iv = substr($raw, 0, self::IV_LEN);
        $tag = substr($raw, self::IV_LEN, self::TAG_LEN);
        $cipher = substr($raw, self::IV_LEN + self::TAG_LEN);

        $plain = openssl_decrypt($cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('Decryption failed — wrong APP_ENCRYPTION_KEY?');
        }

        return $plain;
    }

    private static function key(): string
    {
        $b64 = $_ENV['APP_ENCRYPTION_KEY'] ?? '';
        $key = base64_decode($b64, true);

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('APP_ENCRYPTION_KEY missing or not 32 base64-encoded bytes. See .env.example.');
        }

        return $key;
    }
}
