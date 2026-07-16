<?php

declare(strict_types=1);

/**
 * Batch learning job — the aggregate fallback to the synchronous loop.
 * Detects cross-lead drop-off patterns (needs data across many leads, so it
 * can't run per-message), distills any unprocessed feedback into rules, and
 * reinforces rules whose guided replies kept customers engaged.
 *
 *   php cron/learning_job.php [--quiet-hours=4]
 *
 * Schedule every 15–30 min in production (Task Scheduler / cron).
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(APP_ROOT)->safeLoad();
require APP_ROOT . '/config/constants.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

use App\AI\Memory\ConfidenceScorer;
use App\AI\Memory\FeedbackCollector;
use App\AI\Memory\LearningEngine;

$quietHours = 4.0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--quiet-hours=')) {
        $quietHours = (float) substr($arg, 14);
    }
}

echo '[' . date('Y-m-d H:i:s') . "] learning_job start (quiet-hours=$quietHours)\n";

$patterns = FeedbackCollector::detectDropoffPatterns($quietHours);
echo 'drop-off patterns detected : ' . count($patterns) . "\n";

$learned = LearningEngine::processPending();
echo "rules learned              : $learned\n";

$reinforced = ConfidenceScorer::evaluateUsageOutcomes();
echo "rule usages reinforced     : $reinforced\n";

echo '[' . date('Y-m-d H:i:s') . "] learning_job done\n";
