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


$max = 10;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--max=')) {
        $max = max(1, (int) substr($arg, 6));
    }
}

echo '[' . date('Y-m-d H:i:s') . "] publish_retry start (max=$max)\n";

// The shared cycle uses stored UTC deadlines, bounded retries and delivery
// claims; using the same path prevents cron from bypassing a worker cooldown.
$result = App\Content\ContentPublishWorker::runDue($max);
echo '[' . date('Y-m-d H:i:s') . '] delivery ' . json_encode($result) . "\n";
exit($result['failed'] > 0 ? 1 : 0);
