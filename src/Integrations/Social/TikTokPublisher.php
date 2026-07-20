<?php

declare(strict_types=1);

namespace App\Integrations\Social;

use App\Core\Database;
use App\Models\ApiCredential;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use RuntimeException;

/**
 * TikTok publisher (Content Posting API, photo direct post).
 *
 * Requires an active 'tiktok' credential — a user access token with the
 * video.publish scope from an approved TikTok developer app. Unaudited apps
 * are restricted to SELF_ONLY visibility (hence privacy_level below) and may
 * be blocked from Direct Post entirely; PULL_FROM_URL additionally requires
 * the image domain to be verified in the TikTok developer console. Expect the
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

    public function publish(string $caption, ?string $imageUrl): array
    {
        // TikTok has no text-only post type — same honest-failure rule as IG.
        if ($imageUrl === null) {
            throw new RuntimeException('TikTok requires an image — attach a room photo first.');
        }

        $payload = [
            'post_info' => [
                'title'           => mb_substr($caption, 0, 90),
                'description'     => $caption,
                'privacy_level'   => 'SELF_ONLY',
                'disable_comment' => false,
            ],
            'source_info' => [
                'source'            => 'PULL_FROM_URL',
                'photo_images'      => [$imageUrl],
                'photo_cover_index' => 0,
            ],
            'post_mode'  => 'DIRECT_POST',
            'media_type' => 'PHOTO',
        ];

        if (!$this->isConfigured()) {
            return $this->dryRun($payload);
        }

        $token = ApiCredential::decryptedKeyFor('tiktok')
            ?? throw new RuntimeException('No active TikTok credential.');

        try {
            $response = $this->http->post(self::API . '/post/publish/content/init/', [
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
    private function dryRun(array $payload): array
    {
        Database::run(
            'INSERT INTO ai_activity_log (action, phase, detail) VALUES (?, ?, ?)',
            [
                'social_dry_run_publish',
                'content_creation',
                json_encode(
                    ['note' => 'No active tiktok credential — payload logged, NOT published.', 'platform' => 'tiktok', 'endpoint' => '/post/publish/content/init/', 'payload' => $payload],
                    JSON_UNESCAPED_UNICODE
                ),
            ]
        );

        return ['external_id' => 'sim-tt-' . bin2hex(random_bytes(6)), 'dry_run' => true];
    }
}
