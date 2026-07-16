<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session-based admin login. Single admin account (competition scope), with
 * credentials in .env: ADMIN_USERNAME + ADMIN_PASSWORD_HASH (bcrypt).
 * Includes a CSRF token helper used by every admin form.
 */
final class Auth
{
    public static function check(): bool
    {
        return !empty($_SESSION['admin_logged_in']);
    }

    public static function attempt(string $username, string $password): bool
    {
        $expectedUser = $_ENV['ADMIN_USERNAME'] ?? '';
        $hash = $_ENV['ADMIN_PASSWORD_HASH'] ?? '';

        if ($expectedUser === '' || $hash === '') {
            return false;
        }

        // Prefer a bcrypt hash; tolerate a plaintext value in the env field so a
        // hand-edited .env still logs in (competition-scope convenience).
        $isHash = password_get_info($hash)['algo'] !== null;
        $passwordOk = $isHash ? password_verify($password, $hash) : hash_equals($hash, $password);

        if (hash_equals($expectedUser, $username) && $passwordOk) {
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $username;
            return true;
        }

        return false;
    }

    /** Call at the top of every admin page. Redirects to login if not signed in. */
    public static function requireAdmin(): void
    {
        if (!self::check()) {
            header('Location: /admin/login');
            exit;
        }
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        return is_string($token)
            && !empty($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    /** Convenience guard for POST handlers: dies with 403 on bad token. */
    public static function requireCsrf(): void
    {
        if (!self::verifyCsrf($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            exit('Invalid CSRF token.');
        }
    }
}
