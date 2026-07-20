<?php

declare(strict_types=1);

namespace App\Integrations\Social;

use App\Core\Database;
use App\Models\ApiCredential;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use RuntimeException;

/**
 * Instagram Business publisher (Meta Graph content publishing).
 *
 * Requires an active 'meta_graph' credential (PAGE access token with
 * instagram_content_publish) and ig_user_id in the credential's meta JSON.
 * Two-step container flow: POST /{ig_user_id}/media (image_url + caption)
 * then POST /{ig_user_id}/media_publish (creation_id).
 *
 * Instagram cannot post without media, and Meta fetches image_url
 * server-side — so real publishing needs APP_URL to be publicly reachable.
 */
class InstagramPublisher implements SocialPublisherInterface
{
    private const GRAPH = 'https://graph.facebook.com/v20.0';

    private Client $http;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client(['timeout' => 30]);
    }

    public function isConfigured(): bool
    {
        $cred = ApiCredential::activeFor('meta_graph');
        if ($cred === null) {
            return false;
        }
        $meta = json_decode($cred['meta'] ?? '[]', true) ?: [];

        return ($meta['ig_user_id'] ?? '') !== '';
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

        $meta = json_decode(ApiCredential::activeFor('meta_graph')['meta'] ?? '[]', true) ?: [];
        $igUserId = $meta['ig_user_id'];
        $stored = ApiCredential::decryptedKeyFor('meta_graph')
            ?? throw new RuntimeException('No active Meta Graph credential.');
        // IG content publishing runs on the Page token too — derive it from the
        // stored token when a page_id is on the credential (else use as-is).
        $token = MetaGraph::pageAccessToken($this->http, $stored, (string) ($meta['page_id'] ?? ''));

        $creationId = $this->post("/{$igUserId}/media", $containerPayload, $token)['id'] ?? '';
        if ($creationId === '') {
            throw new RuntimeException('Instagram media container returned no creation id.');
        }
        $published = $this->post("/{$igUserId}/media_publish", ['creation_id' => $creationId], $token);

        return [
            'external_id' => (string) ($published['id'] ?? ''),
            'dry_run'     => false,
        ];
    }

    private function post(string $path, array $payload, string $token): array
    {
        try {
            $response = $this->http->post(self::GRAPH . $path, [
                'headers' => ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json'],
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
                        'note' => 'No active meta_graph credential — payloads logged, NOT published.',
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
