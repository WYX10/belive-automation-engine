<?php

declare(strict_types=1);

/**
 * Memory decay job — rules that haven't been used in a while slowly lose
 * confidence; anything under the retrieval threshold soft-deactivates (kept
 * for the audit trail, no longer injected into prompts).
 *
 *   php cron/memory_decay.php [--stale-days=30]
 *
 * Schedule daily.
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

$staleDays = 30;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--stale-days=')) {
        $staleDays = max(1, (int) substr($arg, 13));
    }
}

echo '[' . date('Y-m-d H:i:s') . "] memory_decay start (stale-days=$staleDays)\n";
$deactivated = ConfidenceScorer::decayStale($staleDays);
echo "rules deactivated (below threshold): $deactivated\n";
echo '[' . date('Y-m-d H:i:s') . "] memory_decay done\n";
