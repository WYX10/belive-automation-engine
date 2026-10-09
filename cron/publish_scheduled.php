<?php

declare(strict_types=1);

/**
 * Publish scheduled posts whose slot has arrived — the half of the content
 * pipeline that makes "pick a time" mean something. An admin approves a draft
 * for a time in the studio (usually one of PostTimingAdvisor's suggested
 * slots); this job is what actually sends it.
 *
 * A post that fails here lands as approved + publish_status=failed, which is
 * exactly what cron/publish_retry.php already picks up ten minutes later — so a
 * transient Graph error costs the slot, not the post.
 *
 *   php cron/publish_scheduled.php [--max=10] [--dry-run]
 *
 * Schedule every 5 min (Windows Task Scheduler):
 *   schtasks /Create /TN "BeLive PublishScheduled" /SC MINUTE /MO 5 ^
 *     /TR "C:\xampp\php\php.exe \"<project>\cron\publish_scheduled.php\""
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(APP_ROOT)->safeLoad();
require APP_ROOT . '/config/constants.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

use App\Integrations\Social\SocialPublishManager;

$max = 10;
$dryRun = in_array('--dry-run', $argv, true);
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--max=')) {
        $max = max(1, (int) substr($arg, 6));
    }
}

echo '[' . date('Y-m-d H:i:s') . "] publish_scheduled start (max=$max" . ($dryRun ? ', dry-run' : '') . ")\n";

$due = SocialPublishManager::due($max);
if ($due === []) {
    echo '[' . date('Y-m-d H:i:s') . "] publish_scheduled done — nothing due\n";
    exit(0);
}

$published = 0;
foreach ($due as $row) {
    $late = round((time() - strtotime((string) $row['scheduled_for'])) / 60);

    if ($dryRun) {
        echo "post #{$row['id']} ({$row['platform']}): due {$row['scheduled_for']} — would publish\n";
        continue;
    }

    try {
        // Same version-checked, row-locked path the admin's Publish button
        // takes, so two overlapping cron runs cannot double-post: the second
        // one loses the version check and is skipped.
        $post = SocialPublishManager::publishScheduled((int) $row['id'], (int) $row['review_version']);
        if ($post['status'] === 'scheduled') {
            echo "post #{$row['id']}: account disconnected; still queued\n";
            continue;
        }
        echo "post #{$row['id']} ({$row['platform']}): {$post['publish_status']}"
            . ($post['publish_status'] === 'failed' ? " — {$post['publish_error']}" : " → {$post['external_post_id']}")
            . " (slot {$row['scheduled_for']}, {$late} min late)\n";
        if (in_array($post['publish_status'], ['published', 'simulated'], true)) {
            $published++;
        }
    } catch (Throwable $e) {
        echo "post #{$row['id']} ({$row['platform']}): skipped — {$e->getMessage()}\n";
    }
}

echo '[' . date('Y-m-d H:i:s') . '] publish_scheduled done — ' . count($due) . " due, $published published\n";
