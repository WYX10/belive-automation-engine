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
