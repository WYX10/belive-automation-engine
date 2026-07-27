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
 * Three-step container flow: POST /{ig_user_id}/media (image_url + caption),
 * poll GET /{creation_id}?fields=status_code until FINISHED, then
 * POST /{ig_user_id}/media_publish (creation_id).
 *
 * Instagram cannot post without media, and Meta fetches image_url
 * server-side — so real publishing needs APP_URL to be publicly reachable.
 */
class InstagramPublisher implements SocialPublisherInterface
{
    private const GRAPH = 'https://graph.facebook.com/v20.0';

    /** error_subcode Meta returns while a container is still being fetched. */
    private const NOT_READY_SUBCODE = '2207027';

    /** Seconds to wait before each readiness re-check — 42s total, images are usually ready inside 5s. */
    private const POLL_DELAYS = [1, 2, 3, 5, 5, 8, 8, 10];

    private Client $http;

    /** @var int[] overridable so tests don't actually sleep */
    private array $pollDelays;

    /** @param int[] $pollDelays */
    public function __construct(?Client $http = null, array $pollDelays = self::POLL_DELAYS)
    {
        $this->http = $http ?? new Client(['timeout' => 30]);
        $this->pollDelays = $pollDelays;
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
        $this->awaitContainerReady($creationId, $token);

        return [
            'external_id' => (string) ($this->publishContainer($igUserId, $creationId, $token)['id'] ?? ''),
            'dry_run'     => false,
        ];
    }

    /**
     * Block until Meta has finished fetching image_url into the container.
     *
     * A container is created instantly but is NOT publishable until Meta has
     * pulled the image server-side; publishing too early is what returns
     * "Media ID is not available" (code 9007, subcode 2207027).
     */
    private function awaitContainerReady(string $creationId, string $token): void
    {
        $status = 'IN_PROGRESS';

        foreach ($this->pollDelays as $delay) {
            $this->pause($delay);
            $container = $this->get("/{$creationId}?fields=status_code,status", $token);
            $status = (string) ($container['status_code'] ?? '');

            if ($status === 'FINISHED') {
                return;
            }
            if ($status === 'ERROR' || $status === 'EXPIRED') {
                $detail = mb_substr((string) ($container['status'] ?? 'no detail given'), 0, 200);
                throw new RuntimeException(
                    "Instagram could not fetch the image ($status): $detail — check that the image URL is publicly reachable (APP_URL) and is a JPEG under 8MB."
                );
            }
        }

        // Still IN_PROGRESS after the whole budget — leave it failed so the
        // admin Retry button (and cron/publish_retry) can try a fresh container.
        throw new RuntimeException(
            'Instagram was still preparing the image after ' . array_sum($this->pollDelays)
            . "s (status $status) — the image host may be slow. Hit Retry in a minute."
        );
    }

    /**
     * media_publish, with one extra wait-and-retry: FINISHED occasionally still
     * races the publish endpoint, and that residual case is exactly subcode 2207027.
     */
    private function publishContainer(string $igUserId, string $creationId, string $token): array
    {
        try {
            return $this->post("/{$igUserId}/media_publish", ['creation_id' => $creationId], $token);
        } catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), self::NOT_READY_SUBCODE)) {
                throw $e;
            }
        }

        $this->pause(5);

        return $this->post("/{$igUserId}/media_publish", ['creation_id' => $creationId], $token);
    }

    /** Seam for tests — a zero delay must not actually sleep. */
    private function pause(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    private function get(string $path, string $token): array
    {
        try {
            $response = $this->http->get(self::GRAPH . $path, [
                'headers' => ['Authorization' => "Bearer {$token}"],
            ]);
        } catch (BadResponseException $e) {
            $body = mb_substr((string) $e->getResponse()->getBody(), 0, 400);
            throw new RuntimeException("Instagram container status check failed ({$e->getResponse()->getStatusCode()}): $body", 0, $e);
        }

        return json_decode((string) $response->getBody(), true) ?? [];
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
                            ['endpoint' => '/{creation_id}?fields=status_code', 'payload' => ['note' => 'polled until status_code=FINISHED']],
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
