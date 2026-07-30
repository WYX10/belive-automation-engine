<?php

declare(strict_types=1);

/**
 * Auto-draft social posts — the scheduled half of the content pipeline.
 * For each platform it features the least-recently-featured available room
 * (skipping any room+platform that already has a pending draft or an approved
 * post awaiting publish), generates a caption with CreateSkill — or a whole
 * 9:16 promo video with PromoVideoDrafter — and leaves the draft in the studio
 * for admin approval. Approval then auto-publishes.
 *
 * The run itself lives in App\Content\AutoDrafter, shared with the studio's
 * Run now button and the web tick (App\Core\Scheduler) that keeps the daily
 * schedule honest on hosts without cron. This file is the command-line door.
 *
 * Platform list, per-run limit, draft type and the content brief default to
 * whatever the admin saved in the content studio (Settings / app_settings); the
 * flags below override them for a one-off run.
 *
 *   php cron/auto_draft_content.php [--if-due] [--platforms=facebook,instagram,tiktok]
 *                                   [--max=3] [--media=image|video] [--brief="..."]
 *
 * --if-due drafts only when the day's run is still owed and automation is not
 * paused, so it is safe to call as often as you like (every 5 minutes, on every
 * deploy, from a scheduled GitHub Action). Without it the run happens now
 * regardless — that is what a human typing the command means.
 *
 * Schedule daily (Windows Task Scheduler):
 *   schtasks /Create /TN "BeLive AutoDraftContent" /SC DAILY /ST 09:00 ^
 *     /TR "C:\xampp\php\php.exe \"<project>\cron\auto_draft_content.php\""
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(APP_ROOT)->safeLoad();
require APP_ROOT . '/config/constants.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

use App\Content\AutoDrafter;

$overrides = [];
$ifDue = false;

foreach ($argv as $arg) {
    if ($arg === '--if-due') {
        $ifDue = true;
    }
    if (str_starts_with($arg, '--platforms=')) {
        $requested = array_filter(array_map('trim', explode(',', substr($arg, 12))));
        $overrides['platforms'] = array_values(array_intersect($requested, CONTENT_PLATFORMS));
    }
    if (str_starts_with($arg, '--max=')) {
        $overrides['max'] = max(1, (int) substr($arg, 6));
    }
    if (str_starts_with($arg, '--brief=')) {
        $overrides['brief'] = trim(substr($arg, 8));
    }
    if (str_starts_with($arg, '--media=')) {
        $requested = trim(substr($arg, 8));
        if (in_array($requested, CONTENT_MEDIA_KINDS, true)) {
            $overrides['media'] = $requested;
        }
    }
}

$now = date('Y-m-d H:i:s');
$result = $ifDue
    ? AutoDrafter::runIfDue('cron', $overrides)
    : AutoDrafter::runNow('cron', $overrides);

if ($result === null) {
    $status = AutoDrafter::status();
    echo "[$now] auto_draft_content skipped — "
        . ($status['enabled']
            ? 'the run for this slot is already done; next one ' . $status['next_slot']->format('D j M, H:i')
            : 'automation is paused in the content studio')
        . "\n";
    exit;
}

echo "[$now] auto_draft_content start (slot {$result['slot']})\n";
foreach ($result['lines'] as $line) {
    echo "  $line\n";
}
if ($result['aborted'] !== null) {
    echo "  aborted — {$result['aborted']}\n";
}
echo '[' . date('Y-m-d H:i:s') . "] auto_draft_content done — {$result['drafted']} draft(s)\n";
exit($result['aborted'] === null ? 0 : 1);
