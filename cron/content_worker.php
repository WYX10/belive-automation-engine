<?php

declare(strict_types=1);

/** php cron/content_worker.php [--once] [--interval=30] [--max=10] */
if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(APP_ROOT)->safeLoad();
require APP_ROOT . '/config/constants.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

use App\Content\ContentPublishWorker;
use App\Core\Scheduler;

$once = in_array('--once', $argv, true);
$interval = 30;
$max = 10;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--interval=')) {
        $interval = max(5, min(60, (int) substr($arg, 11)));
    } elseif (str_starts_with($arg, '--max=')) {
        $max = max(1, min(100, (int) substr($arg, 6)));
    }
}
do {
    @set_time_limit(0);
    try {
        $result = ContentPublishWorker::runDue($max);
        \App\Content\AiVideoJobs::dispatch();
        echo '[' . date('c') . '] delivery ' . json_encode($result) . "\n";
        // Draft in a separate process so an expensive render cannot delay a
        // scheduled delivery. Slot claims already protect overlapping ticks.
        if (!$once) {
            Scheduler::tickAutoDraft();
        }
    } catch (Throwable $e) {
        error_log('[content worker] ' . $e->getMessage());
        if ($once) {
            exit(1);
        }
    }
    @set_time_limit(0);
    if (!$once) {
        sleep($interval);
    }
} while (!$once);
