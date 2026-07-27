<?php

declare(strict_types=1);

namespace App\Integrations\Social;

use App\Core\Database;
use App\Integrations\Meta\InstagramApi;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use RuntimeException;

/**
 * Instagram Business publisher (content publishing).
 *
 * Two-step container flow: POST /{ig_user_id}/media (image_url + caption)
 * then POST /{ig_user_id}/media_publish (creation_id). Instagram cannot post
 * without media, and Meta fetches image_url server-side — so real publishing
 * needs APP_URL to be publicly reachable.
 *
 * The base URL and token come from InstagramApi, because Meta has two
 * Instagram APIs and an app set up for Instagram Login rejects the Page token
 * these calls used to assume. With only a 'meta_graph' credential present,
 * this behaves exactly as it always has.
 */
class InstagramPublisher implements SocialPublisherInterface
{
    private Client $http;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client(['timeout' => 30]);
    }

    public function isConfigured(): bool
    {
        return InstagramApi::isConfigured();
    }

    public function publish(string $caption, ?string $imageUrl): array
    {
        // Hard precondition — checked before any HTTP even in dry-run, so the
        // failure is recorded honestly instead of simulating an impossible post.
        if ($imageUrl === null) {
            throw new RuntimeException('Instagram requires an image — attach a room photo first.');
        }

        $containerPayload = ['image_url' => $imageUrl, 'caption' => $caption];

        if (!$this->isConfigured()) {
            return $this->dryRun($containerPayload);
        }

        $api = InstagramApi::resolve($this->http)
            ?? throw new RuntimeException('No usable Instagram credential.');
        $igUserId = $api['ig_user_id'];

        $creationId = $this->post($api, "/{$igUserId}/media", $containerPayload)['id'] ?? '';
        if ($creationId === '') {
            throw new RuntimeException('Instagram media container returned no creation id.');
        }
        $published = $this->post($api, "/{$igUserId}/media_publish", ['creation_id' => $creationId]);

        return [
            'external_id' => (string) ($published['id'] ?? ''),
            'dry_run'     => false,
        ];
    }

    /** @param array{base:string, token:string, ig_user_id:string, mode:string} $api */
    private function post(array $api, string $path, array $payload): array
    {
        try {
            $response = $this->http->post($api['base'] . $path, [
                'headers' => ['Authorization' => "Bearer {$api['token']}", 'Content-Type' => 'application/json'],
                'json' => $payload,
            ]);
        } catch (BadResponseException $e) {
            $body = mb_substr((string) $e->getResponse()->getBody(), 0, 400);
            throw new RuntimeException("Instagram publish failed ({$e->getResponse()->getStatusCode()}): $body", 0, $e);
        }

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    /** @return array{external_id: string, dry_run: bool} */
    private function dryRun(array $containerPayload): array
    {
        Database::run(
            'INSERT INTO ai_activity_log (action, phase, detail) VALUES (?, ?, ?)',
            [
                'social_dry_run_publish',
                'content_creation',
                json_encode(
                    [
                        'note' => 'No active Instagram credential (instagram, or meta_graph with ig_user_id) — payloads logged, NOT published.',
                        'platform' => 'instagram',
                        'steps' => [
                            ['endpoint' => '/{ig_user_id}/media', 'payload' => $containerPayload],
                            ['endpoint' => '/{ig_user_id}/media_publish', 'payload' => ['creation_id' => '<from step 1>']],
                        ],
                    ],
                    JSON_UNESCAPED_UNICODE
                ),
            ]
        );

        return ['external_id' => 'sim-ig-' . bin2hex(random_bytes(6)), 'dry_run' => true];
    }
}
