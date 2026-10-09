<?php

declare(strict_types=1);

namespace App\Content;

use App\AI\Memory\EpisodicLogger;
use App\AI\Skills\CreateSkill;
use App\Core\Database;
use App\Integrations\WhatsApp\WhatsAppLink;
use App\Models\Room;

/**
 * One promo reel, end to end: CreateSkill writes the scenes and the caption
 * from live room metrics, RoomVideoComposer renders them over the room's own
 * photography, and the result lands in content_posts as a draft awaiting the
 * same admin approval a photo post gets.
 *
 * Called from both halves of the content pipeline — the studio's Generate
 * button and cron/auto_draft_content.php — so the two can never drift.
 */
final class PromoVideoDrafter
{
    /**
     * @param array<string, mixed> $room
     * @param 'manual'|'cron' $via
     * @return array{post_id:int, video_url:string, seconds:float, scenes:int, model:string}
     */
    public static function draft(array $room, string $platform, ?string $brief = null, string $via = 'manual'): array
    {
        $roomId = (int) $room['id'];
        $photos = Room::photoUrls($roomId);
        $shotCount = count($photos) + count(Room::videoUrls($roomId));

        $promo = CreateSkill::videoPromo($room, $platform, $brief, max(1, $shotCount));
        $scenes = array_merge($promo['scenes'], [self::endCard($room)]);

        // Rendering is the slow part (a 15s reel is a few seconds of encode per
        // scene) and PHP's default limit is written for page loads.
        set_time_limit(300);
        $creative = null;
        $videoUrl = RoomVideoComposer::render($room, $scenes, $creative, $brief);
        $scenes = $creative['scenes'];
        unset($creative['scenes']);

        Database::run(
            'INSERT INTO content_posts (platform, media_kind, room_id, caption, status, generated_by_model, generated_via, image_url, video_url, video_script, creative_meta)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $platform,
                'video',
                $roomId,
                $promo['caption'],
                'draft',
                $promo['model'],
                $via,
                $photos[0] ?? null,
                $videoUrl,
                json_encode($scenes, JSON_UNESCAPED_UNICODE),
                json_encode($creative, JSON_UNESCAPED_UNICODE),
            ]
        );
        $postId = (int) Database::pdo()->lastInsertId();

        $seconds = RoomVideoComposer::duration($scenes);
        EpisodicLogger::activity(
            'content_video_drafted',
            'content_creation',
            $promo['model'],
            null,
            sprintf('%s reel for room #%d (%d scenes, %.1fs) → post #%d', $platform, $roomId, count($scenes), $seconds, $postId)
        );

        return [
            'post_id'   => $postId,
            'video_url' => $videoUrl,
            'seconds'   => $seconds,
            'scenes'    => count($scenes),
            'model'     => $promo['model'],
        ];
    }

    /**
     * The closing frame, which is ours and not the model's — a reel that ends
     * without telling a viewer how to reach Eve wasted every second before it.
     * Zero deposit is claimed only when this room's deposit really is zero.
     *
     * @param array<string, mixed> $room
     * @return array{headline:string, sub:string, seconds:float, cta:bool}
     */
    public static function endCard(array $room): array
    {
        $number = WhatsAppLink::number();

        return [
            'headline' => (float) ($room['deposit_amount'] ?? 0) === 0.0
                ? 'Zero deposit. Just bring your bag.'
                : 'Move in ready. Just bring your bag.',
            'sub'      => $number !== ''
                ? 'WhatsApp us on ' . $number . ' to book a viewing'
                : 'WhatsApp us to book a viewing',
            'seconds'  => 3.5,
            'narration' => 'Message us on WhatsApp. Let us book your room viewing.',
            'presenter_action' => 'invite',
            'camera' => 'reveal',
            'cta'      => true,
        ];
    }
}
