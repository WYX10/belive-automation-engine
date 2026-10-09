<?php

declare(strict_types=1);

namespace App\Content;

use App\Core\Database;

/**
 * The performance report behind Admin → Reports: what BeLive published over a
 * period, how it was received, and what it turned into.
 *
 * Everything here is an aggregate of rows the app already owns —
 * content_post_metrics (the platforms' own counters, collected by
 * EngagementCollector), social_replies (comments and DMs Eve answered), leads
 * and bookings. Nothing is modelled or estimated.
 *
 * Two rules the numbers follow:
 *
 *  1. A post counts in a period by when it WENT OUT, but its engagement is the
 *     LATEST snapshot we hold. Platform counters are cumulative, so a post
 *     published on day 1 of the window carries the likes it has today, not the
 *     likes it had that afternoon.
 *
 *  2. Coverage travels with every total. A view count of 900 across 12 posts
 *     means something quite different when only 4 of those posts could be
 *     measured, so `measured` / `unmeasured` are reported alongside, and the
 *     page prints them.
 */
final class EngagementReport
{
    /** Report windows offered in the panel, days => label. */
    public const PERIODS = [7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last 12 months'];

    public const DEFAULT_DAYS = 30;

    /**
     * @return array{
     *   days:int, platform:?string, from:string, to:string,
     *   totals:array<string, mixed>,
     *   platforms:array<string, array<string, mixed>>,
     *   media:array<string, array<string, mixed>>,
     *   top:list<array<string, mixed>>,
     *   funnel:array<string, int>,
     *   publishing:array<string, int>,
     *   generated_at:string
     * }
     */
    public static function build(int $days = self::DEFAULT_DAYS, ?string $platform = null): array
    {
        $days = self::normaliseDays($days);
        $platform = in_array($platform, CONTENT_PLATFORMS, true) ? $platform : null;

        $posts = self::postsInWindow($days, $platform);
        $now = PostTimingAdvisor::now();

        return [
            'days'         => $days,
            'platform'     => $platform,
            'from'         => $now->modify("-$days days")->format('Y-m-d'),
            'to'           => $now->format('Y-m-d'),
            'totals'       => self::aggregate($posts),
            'platforms'    => self::groupBy($posts, 'platform', CONTENT_PLATFORMS),
            'media'        => self::groupBy($posts, 'media_kind', CONTENT_MEDIA_KINDS),
            'top'          => self::top($posts, 5),
            'funnel'       => self::funnel($days, $platform),
            'publishing'   => self::publishing($days, $platform),
            'generated_at' => $now->format('Y-m-d H:i'),
        ];
    }

    /**
     * Published posts in the window, each with its most recent snapshot.
     *
     * @return list<array<string, mixed>>
     */
    public static function postsInWindow(int $days, ?string $platform = null): array
    {
        $days = self::normaliseDays($days);
        $where = $platform === null ? '' : ' AND p.platform = ?';

        return Database::run(
            "SELECT p.id, p.platform, p.media_kind, p.caption, p.publish_status,
                    p.external_post_id, p.generated_by_model, p.generated_via,
                    COALESCE(p.posted_at, p.created_at) AS went_out_at,
                    r.name AS room_name, r.location AS area,
                    m.views, m.likes, m.comments, m.shares, m.source, m.note, m.captured_at
             FROM content_posts p
             LEFT JOIN rooms r ON r.id = p.room_id
             LEFT JOIN content_post_metrics m ON m.id = (" . EngagementCollector::LATEST_SNAPSHOT_ID . ")
             WHERE p.status = 'posted'
               AND COALESCE(p.posted_at, p.created_at) >= (NOW() - INTERVAL $days DAY)$where
             ORDER BY went_out_at DESC, p.id DESC",
            $platform === null ? [] : [$platform]
        )->fetchAll();
    }

    /**
     * Sum a set of posts into one line of the report.
     *
     * Sums skip NULLs rather than reading them as zero, so a metric no platform
     * gave us cannot drag a total down; `measured` says how many of the posts
     * contributed at all.
     *
     * @param list<array<string, mixed>> $posts
     * @return array{posts:int, measured:int, unmeasured:int, views:int, likes:int, comments:int, shares:int, interactions:int, engagement_rate:?float, per_post:float}
     */
    public static function aggregate(array $posts): array
    {
        $line = ['views' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0];
        $measured = 0;

        foreach ($posts as $post) {
            if (($post['source'] ?? null) !== 'platform') {
                continue;
            }
            $measured++;
            foreach (EngagementCollector::METRICS as $metric) {
                if ($post[$metric] !== null) {
                    $line[$metric] += (int) $post[$metric];
                }
            }
        }

        $interactions = $line['likes'] + $line['comments'] + $line['shares'];

        return $line + [
            'posts'        => count($posts),
            'measured'     => $measured,
            'unmeasured'   => count($posts) - $measured,
            'interactions' => $interactions,
            // Interactions per viewer — the industry's engagement rate. Null
            // rather than 0 when nobody could tell us the viewer count, because
            // "we don't know" and "nobody engaged" are different findings.
            'engagement_rate' => $line['views'] > 0 ? round($interactions / $line['views'] * 100, 2) : null,
            'per_post'        => $measured > 0 ? round($interactions / $measured, 1) : 0.0,
        ];
    }

    /**
     * Split the posts by a column and aggregate each bucket. Buckets with no
     * posts are kept (as zeros) so the table always shows all three platforms —
     * "Instagram: nothing went out" is itself a finding.
     *
     * @param list<array<string, mixed>> $posts
     * @param list<string> $keys
     * @return array<string, array<string, mixed>>
     */
    private static function groupBy(array $posts, string $column, array $keys): array
    {
        $buckets = array_fill_keys($keys, []);
        foreach ($posts as $post) {
            $key = (string) ($post[$column] ?? '');
            if (array_key_exists($key, $buckets)) {
                $buckets[$key][] = $post;
            }
        }

        return array_map(self::aggregate(...), $buckets);
    }

    /**
     * The posts that earned the most interactions, best first. Unmeasured posts
     * are excluded — they have no score to rank on, and padding the list with
     * zeros would read as "these performed worst".
     *
     * @param list<array<string, mixed>> $posts
     * @return list<array<string, mixed>>
     */
    private static function top(array $posts, int $limit = 5): array
    {
        $ranked = [];
        foreach ($posts as $post) {
            if (($post['source'] ?? null) !== 'platform') {
                continue;
            }
            $post['interactions'] = (int) $post['likes'] + (int) $post['comments'] + (int) $post['shares'];
            $post['engagement_rate'] = ((int) $post['views']) > 0
                ? round($post['interactions'] / (int) $post['views'] * 100, 2)
                : null;
            $ranked[] = $post;
        }

        usort($ranked, static fn (array $a, array $b): int => $b['interactions'] <=> $a['interactions']);

        return array_slice($ranked, 0, max(1, $limit));
    }

    /**
     * Content → conversation → customer, in the window.
     *
     * The first two steps are the platforms'; the rest are ours. `handoffs` is
     * the wa.me links Eve actually delivered and `attributed` the ones a real
     * WhatsApp lead redeemed — that redemption is why "this tenant came from a
     * comment" is a recorded fact here rather than an inference.
     *
     * @return array{social_events:int, handoffs:int, attributed:int, social_leads:int, all_leads:int, bookings:int}
     */
    public static function funnel(int $days, ?string $platform = null): array
    {
        $days = self::normaliseDays($days);
        // social_replies only ever holds Facebook and Instagram; a TikTok view
        // has no comment data to filter on, so it reports zero rather than
        // silently showing the other platforms' numbers.
        $replyWhere = $platform === null ? '' : ' AND platform = ?';
        $replyParams = $platform === null ? [] : [$platform];

        $replies = $platform === 'tiktok'
            ? ['answered' => 0, 'delivered' => 0, 'converted' => 0]
            : (Database::run(
                "SELECT COUNT(*) AS answered,
                        SUM(CASE WHEN private_reply = 'sent' THEN 1 ELSE 0 END) AS delivered,
                        SUM(CASE WHEN claimed_at IS NOT NULL THEN 1 ELSE 0 END) AS converted
                 FROM social_replies
                 WHERE created_at >= (NOW() - INTERVAL $days DAY)$replyWhere",
                $replyParams
            )->fetch() ?: []);

        // Leads a piece of content could plausibly have produced. A TikTok
        // report counts only the channel TikTok can create.
        $leadChannels = $platform === 'tiktok' ? "('tiktok')" : "('social', 'tiktok')";
        $socialLeads = (int) Database::run(
            "SELECT COUNT(*) FROM leads
             WHERE created_at >= (NOW() - INTERVAL $days DAY) AND source_channel IN $leadChannels"
        )->fetchColumn();

        return [
            'social_events' => (int) ($replies['answered'] ?? 0),
            'handoffs'      => (int) ($replies['delivered'] ?? 0),
            'attributed'    => (int) ($replies['converted'] ?? 0),
            'social_leads'  => $socialLeads,
            'all_leads'     => (int) Database::run(
                "SELECT COUNT(*) FROM leads WHERE created_at >= (NOW() - INTERVAL $days DAY)"
            )->fetchColumn(),
            'bookings'      => (int) Database::run(
                "SELECT COUNT(*) FROM bookings
                 WHERE created_at >= (NOW() - INTERVAL $days DAY) AND status IN ('confirmed', 'completed')"
            )->fetchColumn(),
        ];
    }

    /**
     * What the studio did in the window, regardless of how it performed — how
     * many drafts it wrote, how many an admin let through, and how many of the
     * publishes were real rather than dry-run.
     *
     * @return array{drafted:int, auto_drafted:int, posted:int, published:int, simulated:int, rejected:int, awaiting:int, scheduled:int}
     */
    public static function publishing(int $days, ?string $platform = null): array
    {
        $days = self::normaliseDays($days);
        $where = $platform === null ? '' : ' AND platform = ?';

        $row = Database::run(
            "SELECT COUNT(*) AS drafted,
                    SUM(CASE WHEN generated_via = 'cron' THEN 1 ELSE 0 END) AS auto_drafted,
                    SUM(CASE WHEN status = 'posted' THEN 1 ELSE 0 END) AS posted,
                    SUM(CASE WHEN publish_status = 'published' THEN 1 ELSE 0 END) AS published,
                    SUM(CASE WHEN publish_status = 'simulated' THEN 1 ELSE 0 END) AS simulated,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected,
                    SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS awaiting,
                    SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) AS scheduled
             FROM content_posts
             WHERE created_at >= (NOW() - INTERVAL $days DAY)$where",
            $platform === null ? [] : [$platform]
        )->fetch() ?: [];

        return array_map(static fn ($value): int => (int) $value, [
            'drafted'      => $row['drafted'] ?? 0,
            'auto_drafted' => $row['auto_drafted'] ?? 0,
            'posted'       => $row['posted'] ?? 0,
            'published'    => $row['published'] ?? 0,
            'simulated'    => $row['simulated'] ?? 0,
            'rejected'     => $row['rejected'] ?? 0,
            'awaiting'     => $row['awaiting'] ?? 0,
            'scheduled'    => $row['scheduled'] ?? 0,
        ]);
    }

    /**
     * The report as a CSV an owner can open in Excel — the same numbers on the
     * page, one section per block, so nothing has to be retyped from a screen.
     *
     * @param array<string, mixed> $report from build()
     */
    public static function csv(array $report): string
    {
        $handle = fopen('php://temp', 'r+');
        $put = static fn (array $row) => fputcsv($handle, $row);

        $put(['BeLive Automation Engine — content performance report']);
        $put(['Period', $report['from'] . ' to ' . $report['to'], $report['days'] . ' days']);
        $put(['Channel', $report['platform'] ?? 'all channels']);
        $put(['Generated', $report['generated_at'], 'Asia/Kuala_Lumpur']);
        $put([]);

        $header = ['Segment', 'Posts', 'Measured', 'Viewers', 'Likes', 'Comments', 'Shares', 'Interactions', 'Engagement rate %'];
        $line = static fn (string $name, array $a): array => [
            $name, $a['posts'], $a['measured'], $a['views'], $a['likes'], $a['comments'],
            $a['shares'], $a['interactions'], $a['engagement_rate'] ?? 'n/a',
        ];

        $put(['TOTALS']);
        $put($header);
        $put($line('All published posts', $report['totals']));
        $put([]);

        $put(['BY PLATFORM']);
        $put($header);
        foreach ($report['platforms'] as $platform => $aggregate) {
            $put($line(ucfirst($platform), $aggregate));
        }
        $put([]);

        $put(['BY POST TYPE']);
        $put($header);
        foreach ($report['media'] as $kind => $aggregate) {
            $put($line($kind === 'video' ? 'Promo video' : 'Photo post', $aggregate));
        }
        $put([]);

        $put(['TOP POSTS BY INTERACTIONS']);
        $put(['Post', 'Went out', 'Platform', 'Type', 'Room', 'Viewers', 'Likes', 'Comments', 'Shares', 'Interactions', 'Caption']);
        foreach ($report['top'] as $post) {
            $put([
                '#' . $post['id'],
                $post['went_out_at'],
                $post['platform'],
                $post['media_kind'],
                $post['room_name'] ?? '',
                $post['views'] ?? 'n/a',
                $post['likes'] ?? 'n/a',
                $post['comments'] ?? 'n/a',
                $post['shares'] ?? 'n/a',
                $post['interactions'],
                mb_substr((string) $post['caption'], 0, 180),
            ]);
        }
        $put([]);

        $put(['PUBLISHING ACTIVITY']);
        foreach ($report['publishing'] as $key => $value) {
            $put([ucfirst(str_replace('_', ' ', $key)), $value]);
        }
        $put([]);

        $put(['CONTENT TO CUSTOMER']);
        foreach ([
            'social_events' => 'Comments and DMs Eve answered',
            'handoffs'      => 'WhatsApp hand-offs delivered',
            'attributed'    => 'Hand-offs redeemed on WhatsApp',
            'social_leads'  => 'Leads from social channels',
            'all_leads'     => 'Leads from all channels',
            'bookings'      => 'Viewings confirmed or completed',
        ] as $key => $label) {
            $put([$label, $report['funnel'][$key]]);
        }
        $put([]);

        $put(['NOTE']);
        $put(['Viewers, likes, comments and shares are read from the platforms\' own APIs.']);
        $put(['"Measured" is how many posts returned numbers; the rest are dry-run posts, posts on a platform with no read API, or posts whose token lacks insights permission. They are excluded from the sums, never counted as zero.']);

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private static function normaliseDays(int $days): int
    {
        return array_key_exists($days, self::PERIODS) ? $days : self::DEFAULT_DAYS;
    }
}
