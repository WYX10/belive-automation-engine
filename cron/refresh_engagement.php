<?php

declare(strict_types=1);

/**
 * Keep Admin → Engagement and Admin → Reports current: poll the platforms for
 * viewers, likes, comments and shares on the posts we published, and store one
 * snapshot per post per day.
 *
 * The collector only picks up posts it has not seen inside the staleness window
 * (app_settings.engagement_refresh_minutes, 3h by default), so running this
 * hourly costs nothing on a quiet day and still keeps a busy one fresh. Meta
 * rate-limits per app, which is why the batch is capped rather than sweeping
 * every post every run.
 *
 *   php cron/refresh_engagement.php [--max=25] [--stale=180] [--dry-run]
 *
 * Schedule hourly (Windows Task Scheduler):
 *   schtasks /Create /TN "BeLive RefreshEngagement" /SC HOURLY ^
 *     /TR "C:\xampp\php\php.exe \"<project>\cron\refresh_engagement.php\""
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(APP_ROOT)->safeLoad();
require APP_ROOT . '/config/constants.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

use App\Content\EngagementCollector;

$max = 25;
$stale = null;
$dryRun = in_array('--dry-run', $argv, true);
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--max=')) {
        $max = max(1, (int) substr($arg, 6));
    }
    if (str_starts_with($arg, '--stale=')) {
        $stale = max(0, (int) substr($arg, 8));
    }
}

echo '[' . date('Y-m-d H:i:s') . "] refresh_engagement start (max=$max" . ($dryRun ? ', dry-run' : '') . ")\n";

$queue = EngagementCollector::queue($max, $stale);
if ($queue === []) {
    echo '[' . date('Y-m-d H:i:s') . "] refresh_engagement done — nothing stale enough to re-poll\n";
    exit(0);
}

if ($dryRun) {
    foreach ($queue as $post) {
        echo "post #{$post['id']} ({$post['platform']}): last checked "
            . ($post['last_captured_at'] ?? 'never') . " — would poll\n";
    }
    echo '[' . date('Y-m-d H:i:s') . '] refresh_engagement done — ' . count($queue) . " would be polled\n";
    exit(0);
}

$collector = new EngagementCollector();
$live = 0;
foreach ($queue as $post) {
    $snapshot = $collector->refreshPost($post);
    $live += $snapshot['source'] === 'platform' ? 1 : 0;

    echo "post #{$post['id']} ({$post['platform']}): " . ($snapshot['source'] === 'platform'
        ? sprintf(
            '%s views, %s likes, %s comments, %s shares',
            $snapshot['views'] ?? '—',
            $snapshot['likes'] ?? '—',
            $snapshot['comments'] ?? '—',
            $snapshot['shares'] ?? '—'
        )
        : 'no data — ' . $snapshot['note']) . "\n";
}

echo '[' . date('Y-m-d H:i:s') . '] refresh_engagement done — ' . count($queue)
    . " polled, $live returned live numbers\n";
