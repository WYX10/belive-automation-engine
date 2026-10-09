<?php

declare(strict_types=1);

namespace App\Content;

use App\Core\Database;
use App\Core\Settings;
use App\Integrations\Meta\InstagramApi;
use App\Integrations\Social\MetaGraph;
use App\Models\ApiCredential;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use Throwable;

/**
 * Reads engagement back off the platforms we published to — viewers, likes,
 * comments, shares — and stores one snapshot per post per day in
 * content_post_metrics.
 *
 * The rule this class is built around: NEVER INVENT A NUMBER. A post that went
 * out as a dry-run has no audience; TikTok's Content Posting API hands back no
 * counters; Page insights need read_insights on the token. In each of those
 * cases the metric is written as NULL with a one-line `note` saying why, and
 * the admin panel prints "—". A zero on screen therefore always means the
 * platform said zero, and the studio's engagement view can be trusted to be
 * either real or visibly absent.
 *
 * Where the four numbers come from:
 *
 *   Facebook   GET /{post_id}?fields=likes.summary(true),comments.summary(true),shares
 *              GET /{post_id}/insights?metric=post_impressions_unique   (viewers)
 *   Instagram  GET /{media_id}?fields=like_count,comments_count
 *              GET /{media_id}/insights?metric=views,reach,shares
 *   TikTok     nothing — publishing and reading are different APIs there, and
 *              only the publishing one is wired up.
 *
 * Instagram goes through InstagramApi because Meta ships two Instagram APIs
 * and this install may be on either (see that class).
 */
final class EngagementCollector
{
    /** The four counters the studio reports on, in display order. */
    public const METRICS = ['views', 'likes', 'comments', 'shares'];

    /** Interaction metrics — the numerator of the engagement rate. */
    public const INTERACTIONS = ['likes', 'comments', 'shares'];

    /** Fallback when app_settings has no engagement_refresh_minutes. */
    private const DEFAULT_REFRESH_MINUTES = 180;

    private Client $http;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client(['timeout' => 20]);
    }

    // -- writing -------------------------------------------------------------

    /**
     * Poll the stalest posts and store a snapshot for each.
     *
     * A platform error is recorded on that post's own row and the run carries
     * on — one expired token must not cost the whole sweep.
     *
     * @return array{checked:int, platform:int, unavailable:int}
     */
    public function refresh(int $max = 25, ?int $staleMinutes = null): array
    {
        $summary = ['checked' => 0, 'platform' => 0, 'unavailable' => 0];

        foreach (self::queue($max, $staleMinutes) as $post) {
            $snapshot = $this->refreshPost($post);
            $summary['checked']++;
            $summary[$snapshot['source'] === 'platform' ? 'platform' : 'unavailable']++;
        }

        return $summary;
    }

    /**
     * Fetch one post's numbers and write today's snapshot.
     *
     * @param array<string, mixed> $post a content_posts row
     * @return array<string, mixed> the snapshot as stored
     */
    public function refreshPost(array $post): array
    {
        $snapshot = $this->fetch($post);
        $now = PostTimingAdvisor::now();

        Database::run(
            'INSERT INTO content_post_metrics
                (content_post_id, platform, captured_on, captured_at, source, views, likes, comments, shares, note, raw)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                captured_at = VALUES(captured_at), source = VALUES(source),
                views = VALUES(views), likes = VALUES(likes),
                comments = VALUES(comments), shares = VALUES(shares),
                note = VALUES(note), raw = VALUES(raw)',
            [
                (int) $post['id'],
                (string) $post['platform'],
                $now->format('Y-m-d'),
                $now->format('Y-m-d H:i:s'),
                $snapshot['source'],
                $snapshot['views'],
                $snapshot['likes'],
                $snapshot['comments'],
                $snapshot['shares'],
                $snapshot['note'],
                $snapshot['raw'],
            ]
        );

        return $snapshot;
    }

    /**
     * What a platform says about one post right now. Never throws.
     *
     * @param array<string, mixed> $post
     * @return array{views:?int, likes:?int, comments:?int, shares:?int, source:string, note:?string, raw:?string}
     */
    public function fetch(array $post): array
    {
        $externalId = trim((string) ($post['external_post_id'] ?? ''));
        $publishStatus = $post['publish_status'] ?? null;

        if ($publishStatus === 'simulated') {
            return self::nothingToMeasure(
                'Dry-run post — it never reached the platform, so there is no audience to count.'
            );
        }
        if ($publishStatus !== 'published' || $externalId === '') {
            return self::nothingToMeasure(
                'No platform post id on this row — published before auto-publish existed, or the publish failed.'
            );
        }

        try {
            return match ($post['platform']) {
                'facebook'  => $this->fetchFacebook($externalId),
                'instagram' => $this->fetchInstagram($externalId),
                'tiktok'    => self::nothingToMeasure(
                    'TikTok returns no counters on the Content Posting API — reading views needs the Display API, which is not connected.'
                ),
                default => self::nothingToMeasure('Unknown platform.'),
            };
        } catch (Throwable $e) {
            return self::nothingToMeasure(self::explain($e->getMessage()));
        }
    }

    /**
     * Turn the Graph errors we actually hit into a sentence that names the fix.
     *
     * The raw body is kept on the end — an admin needs the plain English, and
     * whoever ends up in the Meta dashboard needs the code — but a bare
     * "(#10) Object does not exist, cannot be loaded due to missing
     * permission..." in a tooltip tells nobody which of those three it was.
     */
    private static function explain(string $error): string
    {
        $hint = match (true) {
            str_contains($error, 'pages_read_engagement') => 'The Page token cannot read engagement. Add pages_read_engagement (and read_insights for viewers) to the meta_graph token, then re-enter it in Admin → API credentials. ',
            str_contains($error, 'read_insights')         => 'The token cannot read insights. Add read_insights to it and re-enter it in Admin → API credentials. ',
            str_contains($error, 'code":190')             => 'The stored token has expired or been revoked — generate a new one and re-enter it in Admin → API credentials. ',
            default                                       => '',
        };

        return mb_substr($hint . $error, 0, 255);
    }

    /**
     * Facebook Page post. Likes/comments come from the summary counters and
     * shares from the post itself; Facebook omits the `shares` object entirely
     * on a post nobody shared, which is a real zero rather than a gap.
     *
     * @return array{views:?int, likes:?int, comments:?int, shares:?int, source:string, note:?string, raw:?string}
     */
    private function fetchFacebook(string $postId): array
    {
        $credential = ApiCredential::activeFor('meta_graph');
        if ($credential === null) {
            return self::nothingToMeasure('No active Meta Graph credential — add one in Admin → API credentials.');
        }

        $stored = ApiCredential::decryptedKeyFor('meta_graph');
        if ($stored === null) {
            return self::nothingToMeasure('The Meta Graph credential could not be decrypted — re-enter it.');
        }

        $meta = json_decode((string) ($credential['meta'] ?? '[]'), true) ?: [];
        // Post-level reads want the Page token, same as publishing does.
        $token = MetaGraph::pageAccessToken($this->http, $stored, (string) ($meta['page_id'] ?? ''));

        $post = $this->get(
            MetaGraph::BASE . "/{$postId}?fields=likes.summary(true).limit(0),comments.summary(true).limit(0),shares",
            $token
        );

        $note = null;
        $views = null;
        // Insights are a separate permission (read_insights). Losing them must
        // not cost us the like/comment/share counts we already have in hand.
        try {
            $insights = $this->get(MetaGraph::BASE . "/{$postId}/insights?metric=post_impressions_unique", $token);
            $views = self::insightValue($insights, 'post_impressions_unique');
        } catch (Throwable $e) {
            $note = mb_substr('Viewers unavailable — ' . self::explain($e->getMessage()), 0, 255);
        }

        return [
            'views'    => $views,
            'likes'    => self::intOrNull($post['likes']['summary']['total_count'] ?? null),
            'comments' => self::intOrNull($post['comments']['summary']['total_count'] ?? null),
            'shares'   => (int) ($post['shares']['count'] ?? 0),
            'source'   => 'platform',
            'note'     => $note,
            'raw'      => self::encodeRaw(['post' => $post, 'insights' => $insights ?? null]),
        ];
    }

    /**
     * Instagram media. `views` replaced impressions in the current Graph
     * versions; `reach` stands in on media types that do not report it, and
     * `shares` is absent on older media rather than zero.
     *
     * @return array{views:?int, likes:?int, comments:?int, shares:?int, source:string, note:?string, raw:?string}
     */
    private function fetchInstagram(string $mediaId): array
    {
        $api = InstagramApi::resolve($this->http);
        if ($api === null) {
            return self::nothingToMeasure(
                'No usable Instagram credential — add an "instagram" token, or ig_user_id on the meta_graph credential.'
            );
        }

        $media = $this->get($api['base'] . "/{$mediaId}?fields=like_count,comments_count", $api['token']);

        $note = null;
        $insights = null;
        try {
            $insights = $this->get($api['base'] . "/{$mediaId}/insights?metric=views,reach,shares", $api['token']);
        } catch (Throwable $e) {
            // Not every media type supports every metric, and Meta rejects the
            // whole request when one name is unsupported. Reach is the one
            // metric available on every media type — ask for it alone.
            try {
                $insights = $this->get($api['base'] . "/{$mediaId}/insights?metric=reach", $api['token']);
                $note = 'Shares unavailable — this media type does not report them.';
            } catch (Throwable $inner) {
                $note = mb_substr('Viewers unavailable — ' . self::explain($inner->getMessage()), 0, 255);
            }
        }

        return [
            'views'    => self::insightValue($insights ?? [], 'views') ?? self::insightValue($insights ?? [], 'reach'),
            'likes'    => self::intOrNull($media['like_count'] ?? null),
            'comments' => self::intOrNull($media['comments_count'] ?? null),
            'shares'   => self::insightValue($insights ?? [], 'shares'),
            'source'   => 'platform',
            'note'     => $note,
            'raw'      => self::encodeRaw(['media' => $media, 'insights' => $insights]),
        ];
    }

    /**
     * Pull one named metric out of a Graph insights response. Meta puts the
     * number under `values[0].value` on the classic shape and under
     * `total_value.value` on the newer per-media one.
     *
     * @param array<string, mixed> $payload
     */
    private static function insightValue(array $payload, string $metric): ?int
    {
        foreach ($payload['data'] ?? [] as $entry) {
            if (($entry['name'] ?? '') !== $metric) {
                continue;
            }
            $value = $entry['total_value']['value'] ?? $entry['values'][0]['value'] ?? null;

            return is_numeric($value) ? (int) $value : null;
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function get(string $url, string $token): array
    {
        try {
            $response = $this->http->get($url, ['headers' => ['Authorization' => "Bearer {$token}"]]);
        } catch (BadResponseException $e) {
            // 400 chars, same as the publishers: Meta buries the permission
            // name deep in the message, and explain() has to be able to see it.
            $body = mb_substr((string) $e->getResponse()->getBody(), 0, 400);

            throw new \RuntimeException("Graph read failed ({$e->getResponse()->getStatusCode()}): $body", 0, $e);
        }

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    /**
     * The honest empty snapshot: every counter NULL, with the reason attached.
     *
     * @return array{views:null, likes:null, comments:null, shares:null, source:string, note:string, raw:null}
     */
    private static function nothingToMeasure(string $why): array
    {
        return [
            'views' => null, 'likes' => null, 'comments' => null, 'shares' => null,
            'source' => 'unavailable',
            'note' => mb_substr($why, 0, 255),
            'raw' => null,
        ];
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function encodeRaw(array $payload): ?string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? null : mb_substr($json, 0, 60000);
    }

    // -- reading -------------------------------------------------------------

    /**
     * Correlated lookup of a post's most recent snapshot. Written once here
     * because the queue, the overview and the coverage count all need exactly
     * the same "latest row per post" join, and they must not drift apart. Every
     * query using it aliases content_posts as `p`.
     */
    public const LATEST_SNAPSHOT_ID =
        'SELECT m2.id FROM content_post_metrics m2
         WHERE m2.content_post_id = p.id ORDER BY m2.captured_at DESC, m2.id DESC LIMIT 1';

    /**
     * Posts due a re-poll, stalest first: never-captured before long-ago,
     * long-ago before recent. Anything polled inside the refresh window is
     * skipped entirely — a two-week-old post's counters barely move, and Meta
     * rate-limits per app.
     *
     * @return list<array<string, mixed>>
     */
    public static function queue(int $max = 25, ?int $staleMinutes = null): array
    {
        $max = max(1, min(200, $max));
        $stale = max(0, $staleMinutes ?? Settings::getInt('engagement_refresh_minutes', self::DEFAULT_REFRESH_MINUTES));

        // Zero minutes means "everything, now" — the admin's Refresh button.
        // Left as a comparison it would read `captured_at < NOW()`, which drops
        // a post polled in this very second, so the filter is dropped instead.
        $fresh = $stale === 0
            ? ''
            : " AND (m.captured_at IS NULL OR m.captured_at < (NOW() - INTERVAL $stale MINUTE))";

        return Database::run(
            "SELECT p.*, m.captured_at AS last_captured_at
             FROM content_posts p
             LEFT JOIN content_post_metrics m ON m.id = (" . self::LATEST_SNAPSHOT_ID . ")
             WHERE p.status = 'posted'$fresh
             ORDER BY (m.captured_at IS NULL) DESC, m.captured_at ASC, p.id DESC
             LIMIT $max"
        )->fetchAll();
    }

    /**
     * Every published post with its latest numbers, newest post first — the
     * engagement page's table.
     *
     * @return list<array<string, mixed>>
     */
    public static function overview(?string $platform = null, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $where = $platform === null ? '' : ' AND p.platform = ?';

        return Database::run(
            "SELECT p.id, p.platform, p.media_kind, p.caption, p.status, p.publish_status,
                    p.external_post_id, p.posted_at, p.created_at, p.image_url,
                    r.name AS room_name, r.location AS area,
                    m.views, m.likes, m.comments, m.shares, m.source, m.note, m.captured_at
             FROM content_posts p
             LEFT JOIN rooms r ON r.id = p.room_id
             LEFT JOIN content_post_metrics m ON m.id = (" . self::LATEST_SNAPSHOT_ID . ")
             WHERE p.status = 'posted'$where
             ORDER BY COALESCE(p.posted_at, p.created_at) DESC, p.id DESC
             LIMIT $limit",
            $platform === null ? [] : [$platform]
        )->fetchAll();
    }

    /**
     * Daily snapshots for a set of posts, oldest first — the trend behind each
     * row on the engagement page.
     *
     * @param list<int> $postIds
     * @return array<int, list<array<string, mixed>>> keyed by content_post_id
     */
    public static function history(array $postIds, int $days = 14): array
    {
        $postIds = array_values(array_unique(array_map('intval', $postIds)));
        if ($postIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $days = max(1, min(365, $days));

        $history = [];
        foreach (Database::run(
            "SELECT content_post_id, captured_on, captured_at, source, views, likes, comments, shares, note
             FROM content_post_metrics
             WHERE content_post_id IN ($placeholders)
               AND captured_on >= (CURDATE() - INTERVAL $days DAY)
             ORDER BY content_post_id ASC, captured_on ASC",
            $postIds
        )->fetchAll() as $row) {
            $history[(int) $row['content_post_id']][] = $row;
        }

        return $history;
    }

    /** When the collector last stored anything at all. */
    public static function lastRefreshAt(): ?string
    {
        $value = Database::run('SELECT MAX(captured_at) FROM content_post_metrics')->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    /**
     * How much of what we published we can actually measure — printed next to
     * every total so a small number is read as thin coverage, not thin results.
     *
     * @return array{posted:int, measured:int, unmeasured:int, never_checked:int}
     */
    public static function coverage(?string $platform = null): array
    {
        $where = $platform === null ? '' : ' AND p.platform = ?';
        $row = Database::run(
            "SELECT COUNT(*) AS posted,
                    SUM(CASE WHEN m.source = 'platform' THEN 1 ELSE 0 END) AS measured,
                    SUM(CASE WHEN m.id IS NULL THEN 1 ELSE 0 END) AS never_checked
             FROM content_posts p
             LEFT JOIN content_post_metrics m ON m.id = (" . self::LATEST_SNAPSHOT_ID . ")
             WHERE p.status = 'posted'$where",
            $platform === null ? [] : [$platform]
        )->fetch() ?: [];

        $posted = (int) ($row['posted'] ?? 0);
        $measured = (int) ($row['measured'] ?? 0);

        return [
            'posted'        => $posted,
            'measured'      => $measured,
            'unmeasured'    => $posted - $measured,
            'never_checked' => (int) ($row['never_checked'] ?? 0),
        ];
    }
}
