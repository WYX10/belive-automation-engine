<?php

declare(strict_types=1);

/**
 * Auto-draft social posts — the scheduled half of the content pipeline.
 * For each platform it features the least-recently-featured available room
 * (skipping any room+platform that already has a pending draft or an approved
 * post awaiting publish), generates a caption with CreateSkill, and leaves
 * the draft in the studio for admin approval. Approval then auto-publishes.
 *
 *   php cron/auto_draft_content.php [--platforms=facebook,instagram,tiktok] [--max=3]
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

use App\AI\Memory\EpisodicLogger;
use App\AI\Skills\CreateSkill;
use App\Core\Database;
use App\Models\Room;

$platforms = CONTENT_PLATFORMS;
$max = 3;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--platforms=')) {
        $requested = array_filter(array_map('trim', explode(',', substr($arg, 12))));
        $platforms = array_values(array_intersect($requested, CONTENT_PLATFORMS));
    }
    if (str_starts_with($arg, '--max=')) {
        $max = max(1, (int) substr($arg, 6));
    }
}

echo '[' . date('Y-m-d H:i:s') . '] auto_draft_content start (platforms=' . implode(',', $platforms) . ", max=$max)\n";

$drafted = 0;
foreach ($platforms as $platform) {
    if ($drafted >= $max) {
        break;
    }

    try {
        // Least-recently-featured available room with nothing pending on this
        // platform — the LEFT JOIN ... IS NULL is the duplicate guard.
        $room = Database::run(
            "SELECT r.* FROM rooms r
             LEFT JOIN content_posts p ON p.room_id = r.id AND p.platform = ?
                  AND p.status IN ('draft', 'approved')
             WHERE r.status = 'available' AND p.id IS NULL
             ORDER BY (SELECT MAX(created_at) FROM content_posts WHERE room_id = r.id) IS NOT NULL,
                      (SELECT MAX(created_at) FROM content_posts WHERE room_id = r.id) ASC,
                      r.id ASC
             LIMIT 1",
            [$platform]
        )->fetch();

        if (!$room) {
            echo "$platform: no eligible room (all have pending posts or none available)\n";
            continue;
        }

        $caption = CreateSkill::socialCaption($room, $platform);
        Database::run(
            'INSERT INTO content_posts (platform, room_id, caption, status, generated_by_model, generated_via, image_url) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$platform, $room['id'], $caption['text'], 'draft', $caption['model'], 'cron', Room::photoUrls((int) $room['id'])[0] ?? null]
        );
        EpisodicLogger::activity('content_auto_drafted', 'content_creation', $caption['model'], null, "$platform draft for room #{$room['id']} ({$room['name']})");
        echo "$platform: drafted for room #{$room['id']} ({$room['name']}) by {$caption['model']}\n";
        $drafted++;
    } catch (Throwable $e) {
        echo "$platform: FAILED — {$e->getMessage()}\n";
    }
}

echo '[' . date('Y-m-d H:i:s') . "] auto_draft_content done — $drafted draft(s)\n";
