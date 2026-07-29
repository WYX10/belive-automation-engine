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
 * Platform list, per-run limit, draft type and the content brief default to
 * whatever the admin saved in the content studio (Settings / app_settings); the
 * flags below override them for a one-off run.
 *
 *   php cron/auto_draft_content.php [--platforms=facebook,instagram,tiktok] [--max=3] [--media=image|video] [--brief="..."]
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
use App\Content\PromoVideoDrafter;
use App\Content\RoomVideoComposer;
use App\Core\Database;
use App\Core\Settings;
use App\Models\Room;

// Defaults come from the content studio's automation card; CLI flags override.
$platforms = array_values(array_intersect(Settings::getList('content_auto_platforms', CONTENT_PLATFORMS), CONTENT_PLATFORMS));
$max = max(1, Settings::getInt('content_auto_max', 3));
$brief = Settings::get('content_brief', '');
$media = in_array(Settings::get('content_auto_media', 'image'), CONTENT_MEDIA_KINDS, true)
    ? Settings::get('content_auto_media', 'image')
    : 'image';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--platforms=')) {
        $requested = array_filter(array_map('trim', explode(',', substr($arg, 12))));
        $platforms = array_values(array_intersect($requested, CONTENT_PLATFORMS));
    }
    if (str_starts_with($arg, '--max=')) {
        $max = max(1, (int) substr($arg, 6));
    }
    if (str_starts_with($arg, '--brief=')) {
        $brief = trim(substr($arg, 8));
    }
    if (str_starts_with($arg, '--media=')) {
        $requested = trim(substr($arg, 8));
        $media = in_array($requested, CONTENT_MEDIA_KINDS, true) ? $requested : $media;
    }
}

// A scheduled run that silently posted captions when it was told to make reels
// would be the worst of both — stop instead, loudly.
if ($media === 'video' && !RoomVideoComposer::isAvailable()) {
    exit("[" . date('Y-m-d H:i:s') . "] auto_draft_content aborted — video drafts were requested but ffmpeg was not found. Install it or set FFMPEG_BIN in .env.\n");
}

echo '[' . date('Y-m-d H:i:s') . '] auto_draft_content start (platforms=' . implode(',', $platforms) . ", max=$max, media=$media)\n";

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

        if ($media === 'video') {
            $video = PromoVideoDrafter::draft($room, $platform, $brief, 'cron');
            echo sprintf(
                "%s: reel drafted for room #%d (%s) — %d scenes, %.1fs, scripted by %s\n",
                $platform,
                $room['id'],
                $room['name'],
                $video['scenes'],
                $video['seconds'],
                $video['model']
            );
        } else {
            $caption = CreateSkill::socialCaption($room, $platform, $brief);
            Database::run(
                'INSERT INTO content_posts (platform, room_id, caption, status, generated_by_model, generated_via, image_url) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$platform, $room['id'], $caption['text'], 'draft', $caption['model'], 'cron', Room::photoUrls((int) $room['id'])[0] ?? null]
            );
            EpisodicLogger::activity('content_auto_drafted', 'content_creation', $caption['model'], null, "$platform draft for room #{$room['id']} ({$room['name']})");
            echo "$platform: drafted for room #{$room['id']} ({$room['name']}) by {$caption['model']}\n";
        }
        $drafted++;
    } catch (Throwable $e) {
        echo "$platform: FAILED — {$e->getMessage()}\n";
    }
}

echo '[' . date('Y-m-d H:i:s') . "] auto_draft_content done — $drafted draft(s)\n";
