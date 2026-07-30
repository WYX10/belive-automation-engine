<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

/**
 * Engagement — how every published post is actually doing: viewers, likes,
 * comments and shares, read back off the platform that hosts it.
 *
 * The content studio ends at "it went out". This page is the other half: it
 * polls Facebook and Instagram for their own counters (EngagementCollector),
 * keeps one snapshot per post per day, and shows the latest alongside the
 * trend. A number here is either the platform's or visibly absent — see
 * _metrics.php for why that distinction is enforced rather than tidied away.
 */

use App\Content\EngagementCollector;
use App\Content\EngagementReport;
use App\Core\Auth;
use App\Core\Settings;

require dirname(__DIR__) . '/_layout.php';
require dirname(__DIR__) . '/_metrics.php';
Auth::requireAdmin();

/** Posts one manual Refresh will poll. Two Graph calls each — keep it civil. */
const ENGAGEMENT_REFRESH_BATCH = 25;

$platform = in_array($_GET['platform'] ?? '', CONTENT_PLATFORMS, true) ? $_GET['platform'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'refresh') {
    Auth::requireCsrf();

    // A manual refresh means "now" — bypass the cron's staleness window, which
    // exists to spare Meta's rate limit on a schedule, not to tell an admin no.
    set_time_limit(300);
    $summary = (new EngagementCollector())->refresh(ENGAGEMENT_REFRESH_BATCH, 0);

    if ($summary['checked'] === 0) {
        set_flash('success', 'Nothing to check — no posts have gone out yet.');
    } else {
        set_flash(
            $summary['platform'] > 0 ? 'success' : 'danger',
            sprintf(
                'Checked %d post%s — %d returned live numbers, %d had nothing to read.',
                $summary['checked'],
                $summary['checked'] === 1 ? '' : 's',
                $summary['platform'],
                $summary['unavailable']
            )
        );
    }

    header('Location: /admin/engagement' . ($platform !== null ? '?platform=' . urlencode($platform) : ''));
    exit;
}

$posts = EngagementCollector::overview($platform);
$history = EngagementCollector::history(array_map(static fn (array $p): int => (int) $p['id'], $posts));
$coverage = EngagementCollector::coverage($platform);
$lastRefresh = EngagementCollector::lastRefreshAt();
$refreshMinutes = Settings::getInt('engagement_refresh_minutes', 180);

// One aggregate line over exactly what is on screen, so the headline numbers
// and the table can never tell different stories.
$totals = EngagementReport::aggregate($posts);

// The busiest post sets the bar length; everything else is drawn relative to it.
$peakInteractions = 0;
// Why the gaps exist, grouped — when a whole column is dashes it is almost
// always one cause (an expired token, a missing scope), and an admin should not
// have to hover a tooltip to find out which.
$gaps = [];
foreach ($posts as $post) {
    if (($post['source'] ?? '') === 'platform') {
        $peakInteractions = max($peakInteractions, (int) $post['likes'] + (int) $post['comments'] + (int) $post['shares']);
        continue;
    }
    $reason = metric_gap_reason($post);
    $headline = metric_gap_headline($reason);
    $gaps[$headline] ??= ['count' => 0, 'detail' => $reason];
    $gaps[$headline]['count']++;
}
uasort($gaps, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

$stats = [
    ['👀', 'Viewers',  $totals['views'],        'teal'],
    ['❤️', 'Likes',    $totals['likes'],        ''],
    ['💬', 'Comments', $totals['comments'],     'teal'],
    ['🔁', 'Shares',   $totals['shares'],       ''],
];

admin_header('Engagement', 'engagement');
?>
<div class="belive-page-head">
    <div>
        <h1>Engagement</h1>
        <p class="belive-muted" style="font-size:13px">
            Viewers, likes, comments and shares on everything BeLive has published — read from each platform's own API,
            never estimated. <a href="/admin/reports">See the report</a> for the same data by period and channel.
        </p>
    </div>
    <form method="post" action="/admin/engagement<?= $platform !== null ? '?platform=' . e($platform) : '' ?>">
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
        <input type="hidden" name="do" value="refresh">
        <button type="submit" class="belive-btn-primary">🔄 Refresh now</button>
    </form>
</div>

<div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px">
    <?php foreach (['' => '🌐 All channels'] + array_combine(CONTENT_PLATFORMS, array_map(
        static fn (string $p): string => metric_platform_icon($p) . ' ' . ucfirst($p),
        CONTENT_PLATFORMS
    )) as $key => $label): ?>
        <a class="<?= ($platform ?? '') === $key ? 'belive-btn-secondary' : 'belive-btn-ghost' ?>"
           style="padding:6px 14px; font-size:13px"
           href="/admin/engagement<?= $key === '' ? '' : '?platform=' . e($key) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<div class="belive-stat-grid" style="margin-bottom:16px">
    <?php foreach ($stats as [$icon, $label, $value, $tone]): ?>
        <div class="belive-stat">
            <div class="belive-stat-icon <?= e($tone) ?>"><?= $icon ?></div>
            <div>
                <div class="belive-stat-number <?= e($tone) ?>"><?= e(number_format($value)) ?></div>
                <div class="belive-stat-label"><?= e($label) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
    <div class="belive-stat">
        <div class="belive-stat-icon teal">📈</div>
        <div>
            <div class="belive-stat-number teal"><?= $totals['engagement_rate'] === null ? '—' : e(number_format($totals['engagement_rate'], 2)) . '%' ?></div>
            <div class="belive-stat-label">Engagement rate</div>
        </div>
    </div>
</div>

<div class="belive-card metric-coverage" style="margin-bottom:16px">
    <div>
        <strong><?= (int) $coverage['measured'] ?> of <?= (int) $coverage['posted'] ?></strong>
        published post<?= $coverage['posted'] === 1 ? '' : 's' ?> returned live numbers.
        <?php if ($coverage['unmeasured'] > 0): ?>
            The other <?= (int) $coverage['unmeasured'] ?> contribute nothing to the totals above — they are dry-run
            posts, posts on a platform with no read API, or posts whose token cannot see insights. They are left out
            rather than counted as zero.
        <?php endif; ?>
    </div>
    <div class="belive-muted" style="font-size:12.5px">
        <?php if ($lastRefresh !== null): ?>
            Last checked <?= e((new DateTimeImmutable($lastRefresh))->format('D j M, H:i')) ?>.
        <?php else: ?>
            Never checked yet.
        <?php endif; ?>
        <code>cron/refresh_engagement.php</code> re-polls each post every <?= (int) $refreshMinutes ?> minutes;
        Refresh above polls up to <?= ENGAGEMENT_REFRESH_BATCH ?> of the stalest immediately.
    </div>
</div>

<?php if ($gaps !== []): ?>
    <div class="belive-card" style="margin-bottom:16px">
        <div class="belive-card-title">🔍 Why some posts show no numbers</div>
        <ul class="metric-gap-list">
            <?php foreach ($gaps as $headline => $gap): ?>
                <li>
                    <span class="belive-badge orange"><?= (int) $gap['count'] ?> post<?= $gap['count'] === 1 ? '' : 's' ?></span>
                    <span title="<?= e($gap['detail']) ?>"><?= e($headline) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="belive-card">
    <?php if ($posts === []): ?>
        <p class="belive-muted">
            Nothing has been published<?= $platform !== null ? ' to ' . e(ucfirst($platform)) : '' ?> yet.
            Approve a draft in the <a href="/admin/content">content studio</a> and its numbers show up here.
        </p>
    <?php else: ?>
        <div class="belive-table-wrap">
            <table class="belive-table">
                <thead>
                    <tr>
                        <th>Post</th>
                        <th>Went out</th>
                        <th class="metric-cell">👀 Viewers</th>
                        <th class="metric-cell">❤️ Likes</th>
                        <th class="metric-cell">💬 Comments</th>
                        <th class="metric-cell">🔁 Shares</th>
                        <th class="metric-cell">Rate</th>
                        <th>Source</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($posts as $post): ?>
                    <?php
                    $measured = ($post['source'] ?? '') === 'platform';
                    $interactions = $measured
                        ? (int) $post['likes'] + (int) $post['comments'] + (int) $post['shares']
                        : null;
                    $rate = $measured && (int) $post['views'] > 0
                        ? round($interactions / (int) $post['views'] * 100, 2)
                        : null;
                    $trend = $history[(int) $post['id']] ?? [];
                    $wentOut = $post['posted_at'] ?? $post['created_at'];
                    ?>
                    <tr>
                        <td style="max-width:340px">
                            <div style="font-size:13px">
                                <?= metric_platform_icon($post['platform']) ?>
                                <?= $post['media_kind'] === 'video' ? '🎬' : '🖼' ?>
                                <strong>#<?= (int) $post['id'] ?></strong>
                                <?= e($post['room_name'] ? "{$post['room_name']} ({$post['area']})" : 'No room attached') ?>
                            </div>
                            <div class="belive-muted" style="font-size:12px; margin-top:2px">
                                <?= e(mb_substr((string) $post['caption'], 0, 90)) ?><?= mb_strlen((string) $post['caption']) > 90 ? '…' : '' ?>
                            </div>
                            <?php if ($interactions !== null && $peakInteractions > 0): ?>
                                <div class="belive-bar orange" style="margin-top:6px"
                                     title="<?= (int) $interactions ?> interaction<?= $interactions === 1 ? '' : 's' ?> — <?= (int) round($interactions / $peakInteractions * 100) ?>% of the best post here">
                                    <span style="width:<?= (int) round($interactions / $peakInteractions * 100) ?>%"></span>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12.5px; white-space:nowrap">
                            <?= $wentOut ? e((new DateTimeImmutable((string) $wentOut))->format('D j M, H:i')) : '—' ?>
                            <?php if ($post['publish_status'] === 'simulated'): ?>
                                <div><span class="belive-badge orange" style="font-size:11px">simulated</span></div>
                            <?php endif; ?>
                        </td>
                        <?php metric_cells($post); ?>
                        <td class="metric-cell"><?= metric_rate($rate) ?></td>
                        <td style="white-space:nowrap">
                            <?= metric_source_badge($post) ?>
                            <?php if (count($trend) > 1): ?>
                                <details class="metric-trend">
                                    <summary><?= count($trend) ?> daily snapshots</summary>
                                    <table class="metric-trend-table">
                                        <thead><tr><th>Day</th><th>👀</th><th>❤️</th><th>💬</th><th>🔁</th></tr></thead>
                                        <tbody>
                                        <?php foreach (array_reverse($trend) as $snapshot): ?>
                                            <tr>
                                                <td><?= e((new DateTimeImmutable((string) $snapshot['captured_on']))->format('j M')) ?></td>
                                                <?php metric_cells($snapshot); ?>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php admin_footer();
