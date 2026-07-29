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
 * Two-step container flow: POST /{ig_user_id}/media (image_url + caption, or
 * media_type=REELS + video_url for a promo video) then POST
 * /{ig_user_id}/media_publish (creation_id). Instagram cannot post without
 * media, and Meta fetches the media server-side — so real publishing needs
 * APP_URL to be publicly reachable.
 *
 * The base URL and token come from InstagramApi, because Meta has two
 * Instagram APIs and an app set up for Instagram Login rejects the Page token
 * these calls used to assume. With only a 'meta_graph' credential present,
 * this behaves exactly as it always has.
 */
class InstagramPublisher implements SocialPublisherInterface
{
    /** error_subcode Meta returns while a container is still being fetched. */
    private const NOT_READY_SUBCODE = '2207027';

    /** Seconds to wait before each readiness re-check — 42s total, images are usually ready inside 5s. */
    private const POLL_DELAYS = [1, 2, 3, 5, 5, 8, 8, 10];

    /** A reel is transcoded, not just fetched, so it gets a far longer budget — 132s. */
    private const VIDEO_POLL_DELAYS = [3, 5, 5, 8, 8, 10, 10, 15, 15, 18, 20, 15];

    private Client $http;

    /** @var int[] overridable so tests don't actually sleep */
    private array $pollDelays;

    /** @var int[] the reel budget; an explicit image override applies here too */
    private array $videoPollDelays;

    /**
     * @param int[] $pollDelays
     * @param int[]|null $videoPollDelays defaults to the reel budget, unless
     *        $pollDelays was itself overridden — a test that asked for no
     *        sleeping must not start sleeping the moment it posts a video.
     */
    public function __construct(?Client $http = null, array $pollDelays = self::POLL_DELAYS, ?array $videoPollDelays = null)
    {
        $this->http = $http ?? new Client(['timeout' => 30]);
        $this->pollDelays = $pollDelays;
        $this->videoPollDelays = $videoPollDelays
            ?? ($pollDelays === self::POLL_DELAYS ? self::VIDEO_POLL_DELAYS : $pollDelays);
    }

    public function isConfigured(): bool
    {
        return InstagramApi::isConfigured();
    }

    public function publish(string $caption, ?string $mediaUrl, string $mediaKind = 'image'): array
    {
        $isVideo = $mediaKind === 'video';

        // Hard precondition — checked before any HTTP even in dry-run, so the
        // failure is recorded honestly instead of simulating an impossible post.
        if ($mediaUrl === null) {
            throw new RuntimeException($isVideo
                ? 'Instagram requires the rendered video — this reel has none attached.'
                : 'Instagram requires an image — attach a room photo first.');
        }

        // A video goes up as a REELS container: Instagram has no plain video
        // post type left, and media_type is what tells the two apart.
        $containerPayload = $isVideo
            ? ['media_type' => 'REELS', 'video_url' => $mediaUrl, 'caption' => $caption]
            : ['image_url' => $mediaUrl, 'caption' => $caption];

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

        $this->awaitContainerReady($api, $creationId, $isVideo);

        return [
            'external_id' => (string) ($this->publishContainer($api, $igUserId, $creationId)['id'] ?? ''),
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
    private function awaitContainerReady(array $api, string $creationId, bool $isVideo = false): void
    {
        $status = 'IN_PROGRESS';
        $delays = $isVideo ? $this->videoPollDelays : $this->pollDelays;

        foreach ($delays as $delay) {
            $this->pause($delay);
            $container = $this->get($api, "/{$creationId}?fields=status_code,status");
            $status = (string) ($container['status_code'] ?? '');

            if ($status === 'FINISHED') {
                return;
            }
            if ($status === 'ERROR' || $status === 'EXPIRED') {
                $detail = mb_substr((string) ($container['status'] ?? 'no detail given'), 0, 200);
                throw new RuntimeException($isVideo
                    ? "Instagram could not process the video ($status): $detail — check that the video URL is publicly reachable (APP_URL) and is H.264/AAC MP4 under 1GB."
                    : "Instagram could not fetch the image ($status): $detail — check that the image URL is publicly reachable (APP_URL) and is a JPEG under 8MB.");
            }
        }

        // Still IN_PROGRESS after the whole budget — leave it failed so the
        // admin Retry button (and cron/publish_retry) can try a fresh container.
        throw new RuntimeException(sprintf(
            'Instagram was still preparing the %s after %ds (status %s) — %s. Hit Retry in a minute.',
            $isVideo ? 'reel' : 'image',
            array_sum($delays),
            $status,
            $isVideo ? 'transcoding a reel can take a while' : 'the image host may be slow'
        ));
    }

    /**
     * media_publish, with one extra wait-and-retry: FINISHED occasionally still
     * races the publish endpoint, and that residual case is exactly subcode 2207027.
     */
    private function publishContainer(array $api, string $igUserId, string $creationId): array
    {
        try {
            return $this->post($api, "/{$igUserId}/media_publish", ['creation_id' => $creationId]);
        } catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), self::NOT_READY_SUBCODE)) {
                throw $e;
            }
        }

        $this->pause(5);

        return $this->post($api, "/{$igUserId}/media_publish", ['creation_id' => $creationId]);
    }

    /** Seam for tests — a zero delay must not actually sleep. */
    private function pause(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    /** @param array{base:string, token:string, ig_user_id:string, mode:string} $api */
    private function get(array $api, string $path): array
    {
        try {
            $response = $this->http->get($api['base'] . $path, [
                'headers' => ['Authorization' => "Bearer {$api['token']}"],
            ]);
        } catch (BadResponseException $e) {
            $body = mb_substr((string) $e->getResponse()->getBody(), 0, 400);
            throw new RuntimeException("Instagram container status check failed ({$e->getResponse()->getStatusCode()}): $body", 0, $e);
        }

        return json_decode((string) $response->getBody(), true) ?? [];
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
