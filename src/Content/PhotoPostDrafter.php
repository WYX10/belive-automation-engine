<?php

declare(strict_types=1);

namespace App\Content;

use App\AI\Memory\EpisodicLogger;
use App\AI\Skills\CreateSkill;
use App\Core\Database;
use App\Models\Room;

/**
 * One photo post, end to end: CreateSkill writes the caption from live room
 * metrics and the room's cover photo is branded with the mascot for publishing.
 *
 * The video half has PromoVideoDrafter; this is its counterpart, so the studio
 * button and cron/auto_draft_content.php build a photo draft the same way
 * rather than each keeping its own copy of the insert.
 */
final class PhotoPostDrafter
{
    /**
     * @param array<string, mixed> $room
     * @param 'manual'|'cron' $via
     * @return array{post_id:int, model:string, image_url:?string, branded:bool}
     */
    public static function draft(array $room, string $platform, ?string $brief = null, string $via = 'manual'): array
    {
        $roomId = (int) $room['id'];
        $caption = CreateSkill::socialCaption($room, $platform, $brief);

        $photo = Room::photoUrls($roomId)[0] ?? null;
        // Branding is a bonus, never a gate — an unbrandable photo still posts.
        $creative = null;
        $branded = $photo !== null ? BrandedPhotoComposer::brand($photo, $caption['text'], $roomId, $brief, $creative) : null;

        Database::run(
            'INSERT INTO content_posts (platform, media_kind, room_id, caption, status, generated_by_model, generated_via, image_url, creative_meta)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$platform, 'image', $roomId, $caption['text'], 'draft', $caption['model'], $via, $branded ?? $photo, $creative !== null ? json_encode($creative, JSON_UNESCAPED_UNICODE) : null]
        );
        $postId = (int) Database::pdo()->lastInsertId();

        EpisodicLogger::activity(
            $via === 'cron' ? 'content_auto_drafted' : 'content_drafted',
            'content_creation',
            $caption['model'],
            null,
            "$platform draft for room #$roomId ({$room['name']})" . ($branded !== null ? ' — mascot branded' : '')
        );

        return [
            'post_id'   => $postId,
            'model'     => $caption['model'],
            'image_url' => $branded ?? $photo,
            'branded'   => $branded !== null,
        ];
    }
}
