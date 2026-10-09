<?php

declare(strict_types=1);

namespace App\Core;

use App\Content\AutoDrafter;

/**
 * Makes "once a day" true on a host with no crontab.
 *
 * Azure App Service Linux has no cron, so cron/auto_draft_content.php was only
 * ever run by hand — the daily drafts the studio promised simply never arrived.
 * This ticks the schedule off ordinary traffic instead: any admin page load asks
 * whether the day's run is owed and, if it is, hands it to a detached CLI
 * process (or, where the host won't spawn one, to the tail of this request after
 * the page has already been sent). AutoDrafter claims the slot atomically, so
 * ticking on every request still drafts exactly once a day.
 *
 * Nobody has to be logged in for it to work either — see public/cron/tick.php
 * for the token endpoint an external scheduler can call.
 */
final class Scheduler
{
    /**
     * @return 'spawned'|'inline'|'blocked'|null 'blocked' means the run is owed
     *         but this host can neither spawn nor defer it — the studio card
     *         then asks the admin to press Run now.
     */
    public static function tickAutoDraft(): ?string
    {
        // A page an admin asked for must never 500 because the schedule could
        // not be read (an install that has not run its migrations, say).
        try {
            if (!AutoDrafter::isDue()) {
                return null;
            }

            if (self::spawn('cron/auto_draft_content.php', ['--if-due'])) {
                return 'spawned';
            }
        } catch (\Throwable $e) {
            error_log('[scheduler] auto-draft dispatch failed: ' . $e->getMessage());

            return null;
        }

        if (!self::allowed('register_shutdown_function')) {
            return 'blocked';
        }

        // Same process, but only once the page is out of the door: the admin
        // waits for nothing, and the AI calls get an unlimited time budget.
        register_shutdown_function(static function (): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            ignore_user_abort(true);
            @set_time_limit(0);

            try {
                AutoDrafter::runIfDue('web');
            } catch (\Throwable $e) {
                error_log('[scheduler] auto-draft run failed: ' . $e->getMessage());
            }
        });

        return 'inline';
    }

    /**
     * Start a cron script as a detached process and return immediately. False
     * when the host forbids it (disable_functions, no PHP CLI binary), which is
     * a normal answer, not an error — the caller has a fallback.
     */
    public static function spawn(string $script, array $args = []): bool
    {
        $path = APP_ROOT . '/' . ltrim($script, '/');
        $php = self::phpBinary();

        if ($php === null || !is_file($path)) {
            return false;
        }

        $command = escapeshellarg($php);
        // A user-space installation or -c launch has no global php.ini.
        // Preserve the running app's extensions in detached CLI jobs.
        $ini = php_ini_loaded_file();
        if ($ini !== false && is_file($ini)) {
            $command .= ' -c ' . escapeshellarg($ini);
        }
        $command .= ' ' . escapeshellarg($path);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg((string) $arg);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            if (!self::allowed('popen')) {
                return false;
            }
            // The quoted "" is start's title argument — without it start would
            // read the quoted php path as the title and run nothing.
            $handle = @popen('start /B "" ' . $command, 'r');
            if ($handle === false) {
                return false;
            }
            pclose($handle);

            return true;
        }

        if (!self::allowed('exec')) {
            return false;
        }
        @exec('nohup ' . $command . ' > /dev/null 2>&1 &', $output, $code);

        return $code === 0;
    }

    /** The PHP CLI executable — not php-fpm, which cannot run a script. */
    private static function phpBinary(): ?string
    {
        if (PHP_SAPI === 'cli') {
            return PHP_BINARY;
        }

        $exe = PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php';
        $candidates = [
            PHP_BINDIR . DIRECTORY_SEPARATOR . $exe,
            dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . $exe,
            '/usr/local/bin/php',
            '/usr/bin/php',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && (PHP_OS_FAMILY === 'Windows' || is_executable($candidate))) {
                return $candidate;
            }
        }

        return null;
    }

    private static function allowed(string $function): bool
    {
        if (!function_exists($function)) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return !in_array($function, $disabled, true);
    }
}
