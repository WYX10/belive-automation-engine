<?php

declare(strict_types=1);

/**
 * Engagement collection and reporting.
 *
 * The property under test throughout is the one the whole feature rests on:
 * A MISSING METRIC IS NEVER A ZERO. A dry-run post, a platform with no read
 * API, or an insights call the token cannot make must all end up as NULL with
 * a stated reason — and must stay out of every sum, so a coverage gap can
 * never be presented as poor performance.
 *
 * No Meta calls happen here: a Guzzle MockHandler serves the exact Graph
 * payloads, so what is asserted is our mapping, our storage and our arithmetic.
 */

use App\Content\EngagementCollector;
use App\Content\EngagementReport;
use App\Core\Database;
use App\Models\ApiCredential;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

if (($_ENV['APP_ENCRYPTION_KEY'] ?? '') === '') {
    $_ENV['APP_ENCRYPTION_KEY'] = base64_encode(random_bytes(32));
}

$json = static fn (array $body): Response => new Response(200, ['Content-Type' => 'application/json'], json_encode($body));

/**
 * @param array<int, Response> $responses
 * @return array{0: EngagementCollector, 1: ArrayObject}
 */
$collectorWith = static function (array $responses): array {
    $stack = HandlerStack::create(new MockHandler($responses));
    $sent = new ArrayObject();
    $stack->push(Middleware::history($sent));

    return [new EngagementCollector(new Client(['handler' => $stack])), $sent];
};

/** @return int the new content_posts id */
$makePost = static function (string $platform, ?string $publishStatus, ?string $externalId, string $mediaKind = 'image'): int {
    Database::run(
        "INSERT INTO content_posts (platform, media_kind, caption, status, publish_status, external_post_id,
                                    generated_by_model, posted_at)
         VALUES (?, ?, ?, 'posted', ?, ?, 'test-model', NOW())",
        [$platform, $mediaKind, "Room in Setapak on $platform", $publishStatus, $externalId]
    );

    return (int) Database::pdo()->lastInsertId();
};

$postRow = static fn (int $id): array => Database::run('SELECT * FROM content_posts WHERE id = ?', [$id])->fetch();

// ---- a dry-run post has no audience to count --------------------------------
$simulatedId = $makePost('facebook', 'simulated', 'sim-fb-abc123');
[$collector, $sent] = $collectorWith([]);
$snapshot = $collector->fetch($postRow($simulatedId));

check('a simulated post is marked unavailable', $snapshot['source'] === 'unavailable', $snapshot['source']);
check('a simulated post reports no numbers at all', $snapshot['views'] === null && $snapshot['likes'] === null
    && $snapshot['comments'] === null && $snapshot['shares'] === null, json_encode($snapshot));
check('a simulated post says why it cannot be measured', str_contains((string) $snapshot['note'], 'Dry-run'), (string) $snapshot['note']);
check('a simulated post makes no HTTP call', count($sent) === 0, (string) count($sent));

// ---- TikTok publishes but reports nothing back ------------------------------
$tiktokId = $makePost('tiktok', 'published', 'TT-1');
[$collector, $sent] = $collectorWith([]);
$snapshot = $collector->fetch($postRow($tiktokId));
check('TikTok is honestly unmeasurable', $snapshot['source'] === 'unavailable' && str_contains((string) $snapshot['note'], 'TikTok'), (string) $snapshot['note']);
check('TikTok makes no HTTP call', count($sent) === 0, (string) count($sent));

// ---- no credential: nothing to ask, and nothing invented --------------------
$facebookId = $makePost('facebook', 'published', 'PAGE_POST-1');
[$collector, $sent] = $collectorWith([]);
$snapshot = $collector->fetch($postRow($facebookId));
check('without a credential Facebook is unavailable, not zero', $snapshot['source'] === 'unavailable'
    && $snapshot['likes'] === null, json_encode($snapshot));

// No page_id — pageAccessToken() then uses the stored token as-is, so the
// mocked responses line up one-for-one with the calls the collector makes.
$credentialId = ApiCredential::store('meta_graph', 'test engagement', 'PAGE-TOKEN', []);

// ---- Facebook: summary counters + post insights -----------------------------
[$collector, $sent] = $collectorWith([
    $json([
        'likes'    => ['summary' => ['total_count' => 42]],
        'comments' => ['summary' => ['total_count' => 7]],
        'shares'   => ['count' => 3],
        'id'       => 'PAGE_POST-1',
    ]),
    $json(['data' => [['name' => 'post_impressions_unique', 'values' => [['value' => 900]]]]]),
]);
$snapshot = $collector->fetch($postRow($facebookId));

check('Facebook likes come off the summary counter', $snapshot['likes'] === 42, json_encode($snapshot));
check('Facebook comments come off the summary counter', $snapshot['comments'] === 7, json_encode($snapshot));
check('Facebook shares come off the post', $snapshot['shares'] === 3, json_encode($snapshot));
check('Facebook viewers come from post_impressions_unique', $snapshot['views'] === 900, json_encode($snapshot));
check('a fully read Facebook post is sourced to the platform', $snapshot['source'] === 'platform', $snapshot['source']);
check('the raw platform response is kept for audit', str_contains((string) $snapshot['raw'], 'PAGE_POST-1'), mb_substr((string) $snapshot['raw'], 0, 80));

// ---- Facebook omits `shares` entirely on a post nobody shared ---------------
[$collector] = $collectorWith([
    $json(['likes' => ['summary' => ['total_count' => 1]], 'comments' => ['summary' => ['total_count' => 0]]]),
    $json(['data' => [['name' => 'post_impressions_unique', 'values' => [['value' => 10]]]]]),
]);
$snapshot = $collector->fetch($postRow($facebookId));
check('an absent shares object is a real zero, not a gap', $snapshot['shares'] === 0, json_encode($snapshot));
check('a reported zero stays a zero', $snapshot['comments'] === 0, json_encode($snapshot));

// ---- insights refused: keep what we have, admit what we lost ----------------
[$collector] = $collectorWith([
    $json(['likes' => ['summary' => ['total_count' => 5]], 'comments' => ['summary' => ['total_count' => 2]], 'shares' => ['count' => 1]]),
    new Response(403, ['Content-Type' => 'application/json'], json_encode([
        'error' => ['message' => '(#210) requires read_insights permission', 'code' => 210],
    ])),
]);
$snapshot = $collector->fetch($postRow($facebookId));
check('a refused insights call does not lose the like count', $snapshot['likes'] === 5, json_encode($snapshot));
check('a refused insights call leaves viewers NULL', $snapshot['views'] === null, json_encode($snapshot));
check('a refused insights call still counts as a platform read', $snapshot['source'] === 'platform', $snapshot['source']);
check('a refused insights call explains the gap', str_contains((string) $snapshot['note'], 'read_insights'), (string) $snapshot['note']);

// ---- Instagram: media fields + media insights -------------------------------
$instagramId = $makePost('instagram', 'published', 'IG-MEDIA-1', 'video');
ApiCredential::update($credentialId, ['meta' => json_encode(['ig_user_id' => 'IG-USER-1'])]);

[$collector, $sent] = $collectorWith([
    $json(['like_count' => 18, 'comments_count' => 4, 'id' => 'IG-MEDIA-1']),
    $json(['data' => [
        ['name' => 'views', 'values' => [['value' => 512]]],
        ['name' => 'reach', 'values' => [['value' => 400]]],
        ['name' => 'shares', 'values' => [['value' => 9]]],
    ]]),
]);
$snapshot = $collector->fetch($postRow($instagramId));

check('Instagram likes come from like_count', $snapshot['likes'] === 18, json_encode($snapshot));
check('Instagram comments come from comments_count', $snapshot['comments'] === 4, json_encode($snapshot));
check('Instagram viewers prefer views over reach', $snapshot['views'] === 512, json_encode($snapshot));
check('Instagram shares come from media insights', $snapshot['shares'] === 9, json_encode($snapshot));

// ---- Instagram: a media type that reports no shares -------------------------
[$collector] = $collectorWith([
    $json(['like_count' => 3, 'comments_count' => 1]),
    new Response(400, ['Content-Type' => 'application/json'], json_encode([
        'error' => ['message' => '(#100) metric[0] must be one of the following values', 'code' => 100],
    ])),
    $json(['data' => [['name' => 'reach', 'values' => [['value' => 77]]]]]),
]);
$snapshot = $collector->fetch($postRow($instagramId));
check('Instagram falls back to reach when the metric set is rejected', $snapshot['views'] === 77, json_encode($snapshot));
check('an unsupported shares metric stays NULL, not zero', $snapshot['shares'] === null, json_encode($snapshot));

// ---- storage: one snapshot per post per day ---------------------------------
[$collector] = $collectorWith([
    $json(['likes' => ['summary' => ['total_count' => 11]], 'comments' => ['summary' => ['total_count' => 2]], 'shares' => ['count' => 1]]),
    $json(['data' => [['name' => 'post_impressions_unique', 'values' => [['value' => 100]]]]]),
]);
$collector->refreshPost($postRow($facebookId));

[$collector] = $collectorWith([
    $json(['likes' => ['summary' => ['total_count' => 25]], 'comments' => ['summary' => ['total_count' => 3]], 'shares' => ['count' => 2]]),
    $json(['data' => [['name' => 'post_impressions_unique', 'values' => [['value' => 260]]]]]),
]);
$collector->refreshPost($postRow($facebookId));

$rows = Database::run('SELECT * FROM content_post_metrics WHERE content_post_id = ?', [$facebookId])->fetchAll();
check('two polls on one day keep one snapshot', count($rows) === 1, (string) count($rows));
check('the snapshot holds the latest numbers', (int) $rows[0]['likes'] === 25 && (int) $rows[0]['views'] === 260, json_encode($rows[0]));

// ---- the queue skips what was just polled -----------------------------------
$queue = EngagementCollector::queue(50, 180);
$queuedIds = array_map(static fn (array $row): int => (int) $row['id'], $queue);
check('a just-polled post is not re-queued', !in_array($facebookId, $queuedIds, true), implode(',', $queuedIds));
check('a never-polled post is queued', in_array($instagramId, $queuedIds, true), implode(',', $queuedIds));
check('a manual refresh ignores the staleness window',
    in_array($facebookId, array_map(static fn (array $r): int => (int) $r['id'], EngagementCollector::queue(50, 0)), true));

// ---- unmeasurable posts are recorded, and stay out of the sums --------------
[$collector] = $collectorWith([]);
$collector->refreshPost($postRow($simulatedId));
$collector->refreshPost($postRow($tiktokId));

$overview = EngagementCollector::overview();
$byId = [];
foreach ($overview as $row) {
    $byId[(int) $row['id']] = $row;
}
check('the overview carries the latest snapshot onto the post', (int) $byId[$facebookId]['likes'] === 25, json_encode($byId[$facebookId] ?? []));
check('an unmeasurable post appears with a reason, not a zero',
    $byId[$simulatedId]['source'] === 'unavailable' && $byId[$simulatedId]['likes'] === null,
    json_encode($byId[$simulatedId] ?? []));

$totals = EngagementReport::aggregate($overview);
check('totals count every published post', $totals['posts'] === count($overview), $totals['posts'] . ' vs ' . count($overview));
check('totals only sum the posts that returned numbers', $totals['measured'] === 1, (string) $totals['measured']);
check('unmeasurable posts are reported as unmeasured', $totals['unmeasured'] === count($overview) - 1, (string) $totals['unmeasured']);
check('the totals are the measured post\'s own numbers', $totals['likes'] === 25 && $totals['views'] === 260, json_encode($totals));
check('engagement rate is interactions over viewers',
    $totals['engagement_rate'] === round((25 + 3 + 2) / 260 * 100, 2), var_export($totals['engagement_rate'], true));

$coverage = EngagementCollector::coverage();
check('coverage counts what could be measured', $coverage['measured'] === 1 && $coverage['posted'] === count($overview), json_encode($coverage));

// ---- an engagement rate with no viewer count is unknown, not zero -----------
$noViews = EngagementReport::aggregate([
    ['source' => 'platform', 'views' => null, 'likes' => 4, 'comments' => 0, 'shares' => 0],
]);
check('no viewer count means no engagement rate', $noViews['engagement_rate'] === null, var_export($noViews['engagement_rate'], true));
check('a NULL metric is skipped rather than summed as zero', $noViews['views'] === 0 && $noViews['likes'] === 4, json_encode($noViews));

// ---- the report ties together --------------------------------------------------
$report = EngagementReport::build(30);
check('the report windows to a supported period', $report['days'] === 30, (string) $report['days']);
check('an unsupported period falls back to the default', EngagementReport::build(3)['days'] === EngagementReport::DEFAULT_DAYS);
check('the report keeps a row for every platform', array_keys($report['platforms']) === CONTENT_PLATFORMS, implode(',', array_keys($report['platforms'])));
check('the report splits photo from video', array_keys($report['media']) === CONTENT_MEDIA_KINDS, implode(',', array_keys($report['media'])));
check('the platform breakdown finds the Facebook post', $report['platforms']['facebook']['likes'] === 25, json_encode($report['platforms']['facebook']));
check('a platform with nothing measurable reports zero posts measured', $report['platforms']['tiktok']['measured'] === 0, json_encode($report['platforms']['tiktok']));
check('top posts exclude the unmeasurable', count($report['top']) === 1 && (int) $report['top'][0]['id'] === $facebookId, json_encode(array_column($report['top'], 'id')));
check('publishing activity counts the posts sent', $report['publishing']['posted'] >= 4, json_encode($report['publishing']));
check('the funnel reads leads and bookings without failing', isset($report['funnel']['all_leads'], $report['funnel']['bookings']), json_encode($report['funnel']));

$csv = EngagementReport::csv($report);
check('the CSV names the report', str_contains($csv, 'content performance report'), mb_substr($csv, 0, 60));
check('the CSV carries the platform breakdown', str_contains($csv, 'BY PLATFORM') && str_contains($csv, 'Facebook'));
check('the CSV states that unmeasured posts are excluded', str_contains($csv, 'never counted as zero'));

// ---- a deleted post takes its metrics with it -------------------------------
Database::run('DELETE FROM content_posts WHERE id = ?', [$tiktokId]);
$orphans = (int) Database::run('SELECT COUNT(*) FROM content_post_metrics WHERE content_post_id = ?', [$tiktokId])->fetchColumn();
check('deleting a post cascades to its snapshots', $orphans === 0, (string) $orphans);

// Leave no active meta_graph credential behind for the later test files.
ApiCredential::update($credentialId, ['is_active' => 0]);
