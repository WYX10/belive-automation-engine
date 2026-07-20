<?php

declare(strict_types=1);

namespace App\Integrations\Social;

use App\Core\Database;
use App\Models\ApiCredential;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use RuntimeException;

/**
 * Facebook Page publisher (Meta Graph API).
 *
 * Requires an active 'meta_graph' credential whose key is a PAGE access token
 * with pages_manage_posts, and page_id in the credential's meta JSON.
 * With an image: POST /{page_id}/photos. For a site-local photo we upload the
 * bytes directly (multipart 'source') so Facebook never has to fetch our URL —
 * that removes the public-tunnel dependency and the transient "(#324) Missing
 * or invalid image file" fetch errors. A truly remote image URL still uses the
 * 'url' parameter. Without any image: text-only POST /{page_id}/feed —
 * Facebook is the only platform that allows it.
 */
class FacebookPublisher implements SocialPublisherInterface
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

        return ($meta['page_id'] ?? '') !== '';
    }

    public function publish(string $caption, ?string $imageUrl): array
    {
        $endpoint = $imageUrl !== null ? 'photos' : 'feed';
        $payload = $imageUrl !== null
            ? ['url' => $imageUrl, 'caption' => $caption]
            : ['message' => $caption];

        if (!$this->isConfigured()) {
            return $this->dryRun("/{page_id}/$endpoint", $payload);
        }

        $meta = json_decode(ApiCredential::activeFor('meta_graph')['meta'] ?? '[]', true) ?: [];
        $stored = ApiCredential::decryptedKeyFor('meta_graph')
            ?? throw new RuntimeException('No active Meta Graph credential.');
        // Publishing needs a Page token — derive it from the stored token so a
        // user/system-user token doesn't trip the "(#200) publish_actions" error.
        $token = MetaGraph::pageAccessToken($this->http, $stored, (string) $meta['page_id']);

        $options = ['headers' => ['Authorization' => "Bearer {$token}"]];
        $localFile = $this->localFileFor($imageUrl);
        if ($imageUrl !== null && $localFile !== null) {
            // Direct byte upload — no Facebook-side fetch of our URL.
            $options['multipart'] = [
                ['name' => 'source', 'contents' => fopen($localFile, 'rb'), 'filename' => basename($localFile)],
                ['name' => 'caption', 'contents' => $caption],
            ];
        } else {
            $options['headers']['Content-Type'] = 'application/json';
            $options['json'] = $payload;
        }

        try {
            $response = $this->http->post(self::GRAPH . "/{$meta['page_id']}/$endpoint", $options);
        } catch (BadResponseException $e) {
            $body = mb_substr((string) $e->getResponse()->getBody(), 0, 400);
            throw new RuntimeException("Facebook publish failed ({$e->getResponse()->getStatusCode()}): $body", 0, $e);
        }

        $raw = json_decode((string) $response->getBody(), true) ?? [];

        return [
            'external_id' => (string) ($raw['post_id'] ?? $raw['id'] ?? ''),
            'dry_run'     => false,
        ];
    }

    /**
     * Map a public image URL back to its on-disk file under public/ when it's
     * one of our own site-local assets (APP_URL host). Returns null for truly
     * remote URLs, which must be published by 'url' instead.
     */
    private function localFileFor(?string $imageUrl): ?string
    {
        if ($imageUrl === null) {
            return null;
        }
        $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
        if ($appUrl === '' || !str_starts_with($imageUrl, $appUrl)) {
            return null;
        }
        $path = parse_url(substr($imageUrl, strlen($appUrl)), PHP_URL_PATH) ?: '';
        $file = dirname(__DIR__, 3) . '/public' . $path;

        return is_file($file) ? $file : null;
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
                    ['note' => 'No active meta_graph credential — payload logged, NOT published.', 'platform' => 'facebook', 'endpoint' => $endpoint, 'payload' => $payload],
                    JSON_UNESCAPED_UNICODE
                ),
            ]
        );

        return ['external_id' => 'sim-fb-' . bin2hex(random_bytes(6)), 'dry_run' => true];
    }
}
