<?php

declare(strict_types=1);

/**
 * Retry failed publishes — approved posts whose platform publish errored
 * (token expired mid-approve, transient API failure, image URL not yet
 * public). Picks rows that failed more than 10 minutes ago so a broken
 * config isn't hammered, and respects the same optimistic lock as the UI.
 *
 *   php cron/publish_retry.php [--max=10]
 *
 * Schedule every 30 min (Windows Task Scheduler):
 *   schtasks /Create /TN "BeLive PublishRetry" /SC MINUTE /MO 30 ^
 *     /TR "C:\xampp\php\php.exe \"<project>\cron\publish_retry.php\""
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(APP_ROOT)->safeLoad();
require APP_ROOT . '/config/constants.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

use App\Core\Database;
use App\Integrations\Social\SocialPublishManager;

$max = 10;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--max=')) {
        $max = max(1, (int) substr($arg, 6));
    }
}

echo '[' . date('Y-m-d H:i:s') . "] publish_retry start (max=$max)\n";

// publish_status IS NULL covers legacy rows approved under the old
// copy-paste flow (also never reviewed_at-stamped, hence the COALESCE).
$rows = Database::run(
    "SELECT id, platform, review_version FROM content_posts
     WHERE status = 'approved' AND (publish_status = 'failed' OR publish_status IS NULL)
       AND COALESCE(reviewed_at, created_at) < (NOW() - INTERVAL 10 MINUTE)
     ORDER BY COALESCE(reviewed_at, created_at) ASC
     LIMIT " . (int) $max
)->fetchAll();

$published = 0;
foreach ($rows as $row) {
    try {
        $post = SocialPublishManager::retryPublish((int) $row['id'], 'cron', (int) $row['review_version']);
        echo "post #{$row['id']} ({$row['platform']}): {$post['publish_status']}"
            . ($post['publish_status'] === 'failed' ? " — {$post['publish_error']}" : " → {$post['external_post_id']}") . "\n";
        if ($post['publish_status'] !== 'failed') {
            $published++;
        }
    } catch (Throwable $e) {
        echo "post #{$row['id']} ({$row['platform']}): skipped — {$e->getMessage()}\n";
    }
}

echo '[' . date('Y-m-d H:i:s') . '] publish_retry done — ' . count($rows) . " attempted, $published succeeded\n";
