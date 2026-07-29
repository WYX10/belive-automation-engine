<?php

declare(strict_types=1);

namespace App\Integrations\Social;

use App\Core\Database;
use App\Models\ApiCredential;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use RuntimeException;

/**
 * TikTok publisher (Content Posting API) — a photo direct post, or a promo
 * video through the video init endpoint.
 *
 * Requires an active 'tiktok' credential — a user access token with the
 * video.publish scope from an approved TikTok developer app. Unaudited apps
 * are restricted to SELF_ONLY visibility (hence privacy_level below) and may
 * be blocked from Direct Post entirely; PULL_FROM_URL additionally requires
 * the media domain to be verified in the TikTok developer console. Expect the
 * dry-run path in the competition demo.
 */
class TikTokPublisher implements SocialPublisherInterface
{
    private const API = 'https://open.tiktokapis.com/v2';

    private Client $http;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client(['timeout' => 30]);
    }

    public function isConfigured(): bool
    {
        return ApiCredential::activeFor('tiktok') !== null;
    }

    public function publish(string $caption, ?string $mediaUrl, string $mediaKind = 'image'): array
    {
        $isVideo = $mediaKind === 'video';

        // TikTok has no text-only post type — same honest-failure rule as IG.
        if ($mediaUrl === null) {
            throw new RuntimeException($isVideo
                ? 'TikTok requires the rendered video — this reel has none attached.'
                : 'TikTok requires an image — attach a room photo first.');
        }

        $postInfo = [
            'title'           => mb_substr($caption, 0, 90),
            'description'     => $caption,
            'privacy_level'   => 'SELF_ONLY',
            'disable_comment' => false,
        ];

        // Video is TikTok's native post and has its own init endpoint; the
        // photo endpoint is the one that needs the post_mode/media_type pair.
        [$endpoint, $payload] = $isVideo
            ? ['/post/publish/video/init/', [
                'post_info'   => $postInfo,
                'source_info' => ['source' => 'PULL_FROM_URL', 'video_url' => $mediaUrl],
            ]]
            : ['/post/publish/content/init/', [
                'post_info'   => $postInfo,
                'source_info' => [
                    'source'            => 'PULL_FROM_URL',
                    'photo_images'      => [$mediaUrl],
                    'photo_cover_index' => 0,
                ],
                'post_mode'  => 'DIRECT_POST',
                'media_type' => 'PHOTO',
            ]];

        if (!$this->isConfigured()) {
            return $this->dryRun($endpoint, $payload);
        }

        $token = ApiCredential::decryptedKeyFor('tiktok')
            ?? throw new RuntimeException('No active TikTok credential.');

        try {
            $response = $this->http->post(self::API . $endpoint, [
                'headers' => ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json'],
                'json' => $payload,
            ]);
        } catch (BadResponseException $e) {
            $body = mb_substr((string) $e->getResponse()->getBody(), 0, 400);
            throw new RuntimeException("TikTok publish failed ({$e->getResponse()->getStatusCode()}): $body", 0, $e);
        }

        $raw = json_decode((string) $response->getBody(), true) ?? [];

        return [
            'external_id' => (string) ($raw['data']['publish_id'] ?? ''),
            'dry_run'     => false,
        ];
    }

    /** @return array{external_id: string, dry_run: bool} */
    private function dryRun(string $endpoint, array $payload): array
    {
        Database::run(
            'INSERT INTO ai_activity_log (action, phase, detail) VALUES (?, ?, ?)',
            [
                'social_dry_run_publish',
                'content_creation',
                json_encode(
                    ['note' => 'No active tiktok credential — payload logged, NOT published.', 'platform' => 'tiktok', 'endpoint' => $endpoint, 'payload' => $payload],
                    JSON_UNESCAPED_UNICODE
                ),
            ]
        );

        return ['external_id' => 'sim-tt-' . bin2hex(random_bytes(6)), 'dry_run' => true];
    }
}
