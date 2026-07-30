<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

/**
 * Reports — the period view of everything the content pipeline did and what it
 * came back with. Admin → Engagement answers "how is this post doing?"; this
 * page answers "how did last month go, and which channel earned it?".
 *
 * Every figure is an aggregate of rows the app already holds (EngagementReport):
 * platform counters from content_post_metrics, Eve's own social_replies, leads
 * and bookings. Coverage is printed beside the totals, because a total drawn
 * from four measurable posts out of twelve must not read like a total drawn
 * from twelve.
 */

use App\Content\EngagementCollector;
use App\Content\EngagementReport;
use App\Core\Auth;

require dirname(__DIR__) . '/_layout.php';
require dirname(__DIR__) . '/_metrics.php';
Auth::requireAdmin();

$days = (int) ($_GET['days'] ?? EngagementReport::DEFAULT_DAYS);
$platform = in_array($_GET['platform'] ?? '', CONTENT_PLATFORMS, true) ? $_GET['platform'] : null;

$report = EngagementReport::build($days, $platform);
$days = $report['days'];
$totals = $report['totals'];
$funnel = $report['funnel'];
$publishing = $report['publishing'];
$lastRefresh = EngagementCollector::lastRefreshAt();

/** Keep the current filters when only one of them changes. */
$link = static function (array $overrides) use ($days, $platform): string {
    $query = array_filter(
        array_merge(['days' => $days, 'platform' => $platform], $overrides),
        static fn ($value): bool => $value !== null && $value !== ''
    );

    return '/admin/reports' . ($query === [] ? '' : '?' . http_build_query($query));
};

$headline = [
    ['📣', 'Posts published', number_format($totals['posts']), ''],
    ['👀', 'Viewers reached', number_format($totals['views']), 'teal'],
    ['❤️', 'Likes', number_format($totals['likes']), ''],
    ['💬', 'Comments', number_format($totals['comments']), 'teal'],
    ['🔁', 'Shares', number_format($totals['shares']), ''],
    ['📈', 'Engagement rate', $totals['engagement_rate'] === null ? '—' : number_format($totals['engagement_rate'], 2) . '%', 'teal'],
];

/**
 * One row of a breakdown table. Segments with no posts still print, because
 * "nothing went out on Instagram this month" is a finding of its own.
 *
 * @param array<string, mixed> $aggregate
 */
$segmentRow = static function (string $label, string $icon, array $aggregate): void {
    ?>
    <tr<?= $aggregate['posts'] === 0 ? ' class="metric-row-empty"' : '' ?>>
        <td style="white-space:nowrap"><?= $icon ?> <?= e($label) ?></td>
        <td class="metric-cell"><?= e(number_format($aggregate['posts'])) ?></td>
        <td class="metric-cell">
            <?php if ($aggregate['posts'] === 0): ?>
                <span class="metric-missing">—</span>
            <?php else: ?>
                <?= e((string) $aggregate['measured']) ?>
                <?php if ($aggregate['unmeasured'] > 0): ?>
                    <span class="belive-muted" style="font-size:11.5px" title="<?= (int) $aggregate['unmeasured'] ?> post(s) returned no numbers and are excluded from the sums.">
                        (+<?= (int) $aggregate['unmeasured'] ?> unmeasured)</span>
                <?php endif; ?>
            <?php endif; ?>
        </td>
        <?php foreach (App\Content\EngagementCollector::METRICS as $metric): ?>
            <td class="metric-cell">
                <?= $aggregate['measured'] === 0
                    ? '<span class="metric-missing" title="No post in this segment returned numbers.">—</span>'
                    : metric_number((int) $aggregate[$metric]) ?>
            </td>
        <?php endforeach; ?>
        <td class="metric-cell"><?= metric_rate($aggregate['engagement_rate']) ?></td>
        <td class="metric-cell"><?= $aggregate['measured'] === 0 ? '<span class="metric-missing">—</span>' : e(number_format($aggregate['per_post'], 1)) ?></td>
    </tr>
    <?php
};

admin_header('Reports', 'reports');
?>
<div class="belive-page-head">
    <div>
        <h1>Performance report</h1>
        <p class="belive-muted" style="font-size:13px">
            <?= e($report['from']) ?> → <?= e($report['to']) ?> ·
            <?= $platform === null ? 'all channels' : e(ucfirst($platform)) ?> ·
            generated <?= e($report['generated_at']) ?> (Asia/Kuala_Lumpur)
        </p>
    </div>
    <a class="belive-btn-primary"
       href="/admin/reports/export?<?= e(http_build_query(array_filter(['days' => $days, 'platform' => $platform]))) ?>">⬇ Export CSV</a>
</div>

<div style="display:flex; gap:16px; flex-wrap:wrap; margin-bottom:16px">
    <div style="display:flex; gap:8px; flex-wrap:wrap">
        <?php foreach (EngagementReport::PERIODS as $period => $label): ?>
            <a class="<?= $days === $period ? 'belive-btn-secondary' : 'belive-btn-ghost' ?>"
               style="padding:6px 14px; font-size:13px"
               href="<?= e($link(['days' => $period])) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap">
        <?php foreach (['' => '🌐 All channels'] + array_combine(CONTENT_PLATFORMS, array_map(
            static fn (string $p): string => metric_platform_icon($p) . ' ' . ucfirst($p),
            CONTENT_PLATFORMS
        )) as $key => $label): ?>
            <a class="<?= ($platform ?? '') === $key ? 'belive-btn-secondary' : 'belive-btn-ghost' ?>"
               style="padding:6px 14px; font-size:13px"
               href="<?= e($link(['platform' => $key === '' ? null : $key])) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="belive-stat-grid" style="margin-bottom:16px">
    <?php foreach ($headline as [$icon, $label, $value, $tone]): ?>
        <div class="belive-stat">
            <div class="belive-stat-icon <?= e($tone) ?>"><?= $icon ?></div>
            <div>
                <div class="belive-stat-number <?= e($tone) ?>"><?= e($value) ?></div>
                <div class="belive-stat-label"><?= e($label) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="belive-card metric-coverage" style="margin-bottom:16px">
    <div>
        <?php if ($totals['posts'] === 0): ?>
            Nothing went out in this window<?= $platform !== null ? ' on ' . e(ucfirst($platform)) : '' ?>.
            Everything below is zero because there is nothing to report, not because it performed badly.
        <?php else: ?>
            <strong><?= (int) $totals['measured'] ?> of <?= (int) $totals['posts'] ?></strong> posts in this window
            returned numbers from their platform.
            <?php if ($totals['unmeasured'] > 0): ?>
                The other <?= (int) $totals['unmeasured'] ?> are excluded from every sum — a post we cannot measure is
                left out, never counted as a zero.
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <div class="belive-muted" style="font-size:12.5px">
        <?= $lastRefresh === null
            ? 'Counters have never been polled — open <a href="/admin/engagement">Engagement</a> and hit Refresh.'
            : 'Counters last polled ' . e((new DateTimeImmutable($lastRefresh))->format('D j M, H:i'))
              . '. <a href="/admin/engagement">Refresh them</a>.' ?>
    </div>
</div>

<div class="belive-card" style="margin-bottom:16px">
    <div class="belive-card-title">📊 By channel</div>
    <div class="belive-table-wrap">
        <table class="belive-table">
            <thead>
                <tr>
                    <th>Channel</th><th class="metric-cell">Posts</th><th class="metric-cell">Measured</th>
                    <th class="metric-cell">👀 Viewers</th><th class="metric-cell">❤️ Likes</th>
                    <th class="metric-cell">💬 Comments</th><th class="metric-cell">🔁 Shares</th>
                    <th class="metric-cell">Rate</th><th class="metric-cell">Per post</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($report['platforms'] as $key => $aggregate): ?>
                <?php $segmentRow(ucfirst($key), metric_platform_icon($key), $aggregate); ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="belive-muted" style="font-size:12px; margin:10px 0 0">
        TikTok publishes but reports nothing back — its Content Posting API returns no counters, so its row stays
        unmeasured until the Display API is connected. "Per post" is interactions divided by measured posts.
    </p>
</div>

<div class="belive-card" style="margin-bottom:16px">
    <div class="belive-card-title">🎬 Photo posts vs promo videos</div>
    <div class="belive-table-wrap">
        <table class="belive-table">
            <thead>
                <tr>
                    <th>Post type</th><th class="metric-cell">Posts</th><th class="metric-cell">Measured</th>
                    <th class="metric-cell">👀 Viewers</th><th class="metric-cell">❤️ Likes</th>
                    <th class="metric-cell">💬 Comments</th><th class="metric-cell">🔁 Shares</th>
                    <th class="metric-cell">Rate</th><th class="metric-cell">Per post</th>
                </tr>
            </thead>
            <tbody>
                <?php $segmentRow('Photo post', '🖼', $report['media']['image']); ?>
                <?php $segmentRow('Promo video', '🎬', $report['media']['video']); ?>
            </tbody>
        </table>
    </div>
</div>

<div class="belive-card" style="margin-bottom:16px">
    <div class="belive-card-title">🏆 Top posts by interactions</div>
    <?php if ($report['top'] === []): ?>
        <p class="belive-muted" style="font-size:13px">
            No post in this window has measurable numbers yet, so there is nothing to rank.
        </p>
    <?php else: ?>
        <div class="belive-table-wrap">
            <table class="belive-table">
                <thead>
                    <tr>
                        <th>Post</th><th>Went out</th>
                        <th class="metric-cell">👀</th><th class="metric-cell">❤️</th>
                        <th class="metric-cell">💬</th><th class="metric-cell">🔁</th>
                        <th class="metric-cell">Interactions</th><th class="metric-cell">Rate</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($report['top'] as $rank => $post): ?>
                    <tr>
                        <td style="max-width:320px">
                            <div style="font-size:13px">
                                <strong>#<?= $rank + 1 ?></strong>
                                <?= metric_platform_icon($post['platform']) ?>
                                <?= $post['media_kind'] === 'video' ? '🎬' : '🖼' ?>
                                <?= e($post['room_name'] ? "{$post['room_name']} ({$post['area']})" : 'No room attached') ?>
                            </div>
                            <div class="belive-muted" style="font-size:12px; margin-top:2px">
                                <?= e(mb_substr((string) $post['caption'], 0, 90)) ?><?= mb_strlen((string) $post['caption']) > 90 ? '…' : '' ?>
                            </div>
                        </td>
                        <td style="font-size:12.5px; white-space:nowrap">
                            <?= e((new DateTimeImmutable((string) $post['went_out_at']))->format('D j M, H:i')) ?>
                        </td>
                        <?php metric_cells($post); ?>
                        <td class="metric-cell"><strong><?= e(number_format((int) $post['interactions'])) ?></strong></td>
                        <td class="metric-cell"><?= metric_rate($post['engagement_rate']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="belive-row" style="margin-bottom:16px">
    <div class="belive-col">
        <div class="belive-card" style="height:100%">
            <div class="belive-card-title">✍️ What the studio did</div>
            <p class="belive-muted" style="font-size:13px; margin-top:-4px">
                Drafts written in this window and what happened to them — performance aside, this is the throughput.
            </p>
            <table class="belive-table">
                <tbody>
                    <?php foreach ([
                        'drafted'      => ['Drafts written', 'Every caption the AI produced, on demand or on schedule.'],
                        'auto_drafted' => ['— of those, drafted by cron', 'Written without anyone asking, by cron/auto_draft_content.php.'],
                        'posted'       => ['Approved and sent', 'Drafts an admin let through.'],
                        'published'    => ['— really on the platform', 'A live post exists, with a platform post id.'],
                        'simulated'    => ['— dry-run only', 'No platform credential was active, so the payload was logged, not published.'],
                        'scheduled'    => ['Waiting on a slot', 'Approved for a time that has not arrived yet.'],
                        'awaiting'     => ['Awaiting approval', 'Sitting in the studio queue right now.'],
                        'rejected'     => ['Rejected', 'Sent back with a reason, which feeds the next draft.'],
                    ] as $key => [$label, $why]): ?>
                        <tr>
                            <td style="font-size:13px" title="<?= e($why) ?>"><?= e($label) ?></td>
                            <td class="metric-cell"><strong><?= e(number_format($publishing[$key])) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="belive-col">
        <div class="belive-card" style="height:100%">
            <div class="belive-card-title">🎯 Content to customer</div>
            <p class="belive-muted" style="font-size:13px; margin-top:-4px">
                What the engagement above turned into. Each step is a recorded row, not an attribution model.
            </p>
            <table class="belive-table">
                <tbody>
                    <?php foreach ([
                        'social_events' => ['Comments and DMs answered', 'Every FB/IG event Eve replied to (social_replies).'],
                        'handoffs'      => ['WhatsApp hand-offs delivered', 'A private reply carrying the wa.me link actually went out.'],
                        'attributed'    => ['Hand-offs redeemed on WhatsApp', 'The link token arrived back on WhatsApp — proof the lead came from a post.'],
                        'social_leads'  => ['Leads from social channels', 'Leads whose source_channel is social or tiktok.'],
                        'all_leads'     => ['Leads from all channels', 'Everything Eve captured, for context.'],
                        'bookings'      => ['Viewings confirmed', 'Bookings confirmed or completed in the same window.'],
                    ] as $key => [$label, $why]): ?>
                        <tr>
                            <td style="font-size:13px" title="<?= e($why) ?>"><?= e($label) ?></td>
                            <td class="metric-cell"><strong><?= e(number_format($funnel[$key])) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="belive-muted" style="font-size:12px; margin:10px 0 0">
                Bookings are counted in the window, not traced to a specific post — a viewing is rarely booked by the
                person who liked the photo, and pretending otherwise would overstate the funnel.
            </p>
        </div>
    </div>
</div>

<div class="belive-card">
    <div class="belive-card-title">📌 Where these numbers come from</div>
    <ul class="belive-check-list">
        <li>Viewers, likes, comments and shares are read from Facebook's and Instagram's own APIs by
            <code>src/Content/EngagementCollector.php</code> and stored as one snapshot per post per day.</li>
        <li>A post counts in this window by when it <em>went out</em>; its engagement is the latest snapshot we hold,
            because platform counters are cumulative.</li>
        <li>A metric the platform did not return is stored as NULL and shown as "—". It never becomes a zero, so a
            permission gap can never be mistaken for a post nobody liked.</li>
        <li>Engagement rate is (likes + comments + shares) ÷ viewers, over measured posts only.</li>
    </ul>
</div>
<?php admin_footer();
