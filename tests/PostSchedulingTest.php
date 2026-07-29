<?php

declare(strict_types=1);

/**
 * Suggested posting times, and the scheduled publish they feed.
 *
 * Two promises are under test:
 *   1. the suggestion is a reading of our own data — seed a week of engagement
 *      into one weekday/hour and the advisor must point at it (and at the hour
 *      before it, which is where a post has to be to earn that engagement),
 *   2. a scheduled post sits still until its slot, is invisible to the retry
 *      cron while it waits, and then publishes through the same version-checked
 *      path an admin's own button takes.
 */

use App\Content\PostTimingAdvisor;
use App\Core\Database;
use App\Integrations\Social\SocialPublishManager;

// The test database is shared across the whole run, so every assertion here is
// against the delta this file creates, never an absolute total.
$before = PostTimingAdvisor::heatmap();
check('heatmap covers exactly one week, hour by hour',
    count($before['grid']) === 7 && count($before['grid'][0]) === 24,
    count($before['grid']) . ' days');
check('a thin database still yields a usable grid rather than a blank one',
    $before['peak']['score'] > 0.0 && $before['confidence'] === 'low');
check('every cell is a 0..1 score', (function (array $grid): bool {
    foreach ($grid as $hours) {
        foreach ($hours as $cell) {
            if ($cell['score'] < 0.0 || $cell['score'] > 1.0) {
                return false;
            }
        }
    }
    return true;
})($before['grid']));

$blankSuggestions = PostTimingAdvisor::suggestions(null, 3, $before);
check('three distinct slots are suggested even with almost no history',
    count($blankSuggestions) === 3
    && count(array_unique(array_column($blankSuggestions, 'day'))) === 3);
check('a suggestion names its next real occurrence in the future',
    $blankSuggestions[0]['next'] > PostTimingAdvisor::now());

// ---- the advisor reading real engagement -----------------------------------
// Twelve Wednesdays of comments at 21:00 — inside the 90-day window, and heavy
// enough to speak over whatever the earlier test files left lying around.
$seeded = 0;
$wednesday21 = new DateTimeImmutable('last wednesday 21:20');
for ($week = 0; $week < 12; $week++) {
    $at = $wednesday21->modify("-$week weeks")->format('Y-m-d H:i:s');
    for ($i = 0; $i < 12; $i++) {
        Database::run(
            "INSERT INTO social_replies (platform, event_type, object_id, sender_id, created_at)
             VALUES ('facebook', 'comment', ?, 'seed-sender', ?)",
            ["seed-comment-$week-$i", $at]
        );
        $seeded++;
    }
}

$learned = PostTimingAdvisor::heatmap();
check('the advisor counts exactly the engagement it was given',
    $learned['sources']['social'] === $before['sources']['social'] + $seeded,
    "social={$learned['sources']['social']}");
check('the peak lands on the Wednesday hours the audience actually engaged in',
    $learned['peak']['day'] === 2 && in_array($learned['peak']['hour'], [20, 21], true),
    "day={$learned['peak']['day']} hour={$learned['peak']['hour']}");
check('20:00 is credited too — a post must already be up to be commented on at 21:00',
    $learned['grid'][2][20]['score'] > $learned['grid'][2][18]['score']);
check('own data now outweighs the default curve more than it did',
    $learned['observed_share'] > $before['observed_share']);
check('more evidence raises the stated confidence',
    $learned['confidence'] === 'high', $learned['confidence']);

$learnedSuggestions = PostTimingAdvisor::suggestions(null, 3, $learned);
check('the top suggestion is the learned slot',
    $learnedSuggestions[0]['day'] === 2 && in_array($learnedSuggestions[0]['hour'], [20, 21], true),
    $learnedSuggestions[0]['label']);
check('suggestions are spread across the week, not three slices of one peak',
    count(array_unique(array_column($learnedSuggestions, 'day'))) === 3);
check('the top suggestion cites the events behind it',
    str_contains($learnedSuggestions[0]['why'], 'engagement event'), $learnedSuggestions[0]['why']);

// TikTok has no comment webhook, so its view must not borrow Facebook's.
check('a TikTok view does not count Facebook comments as its own',
    PostTimingAdvisor::heatmap('tiktok')['sources']['social'] === 0);
check('an Instagram view does not count Facebook comments either',
    PostTimingAdvisor::heatmap('instagram')['sources']['social'] < $seeded);
check('a Facebook view does count them',
    PostTimingAdvisor::heatmap('facebook')['sources']['social'] >= $seeded);

// ---- next occurrence -------------------------------------------------------
$next = PostTimingAdvisor::nextOccurrence(2, 21);
check('next occurrence falls on the requested weekday and hour',
    (int) $next->format('N') === 3 && (int) $next->format('G') === 21,
    $next->format('D H:i'));
check('next occurrence is far enough out for the publisher cron to reach it',
    $next > PostTimingAdvisor::now()->modify('+' . CONTENT_SCHEDULE_MIN_LEAD_MINUTES . ' minutes'));

// ---- scheduling a draft ----------------------------------------------------
$makeDraft = static function (string $platform = 'facebook'): array {
    Database::run(
        "INSERT INTO content_posts (platform, media_kind, caption, status, generated_by_model, generated_via)
         VALUES (?, 'image', 'Scheduling test caption #BeLiveSolopreneur', 'draft', 'stub-model', 'manual')",
        [$platform]
    );

    return Database::run('SELECT * FROM content_posts WHERE id = ?', [(int) Database::pdo()->lastInsertId()])->fetch();
};

$draft = $makeDraft();
$slot = PostTimingAdvisor::suggestions('facebook', 1)[0]['next'];
$scheduled = SocialPublishManager::schedule(
    (int) $draft['id'],
    'timing-test-admin',
    (int) $draft['review_version'],
    $slot->format('Y-m-d H:i:s'),
    'suggested'
);
check('a scheduled draft holds its own status, not "approved"',
    $scheduled['status'] === 'scheduled', $scheduled['status']);
check('the chosen slot, who chose it and where it came from are all recorded',
    $scheduled['scheduled_for'] === $slot->format('Y-m-d H:i:s')
    && $scheduled['scheduled_by'] === 'timing-test-admin'
    && $scheduled['schedule_source'] === 'suggested');
check('nothing has been published yet', $scheduled['publish_status'] === null && $scheduled['posted_at'] === null);
check('a scheduled post is counted as scheduled in the studio tabs',
    SocialPublishManager::counts()['scheduled'] === 1);

// The retry cron's own query — a waiting post must be invisible to it.
$retryQueue = Database::run(
    "SELECT id FROM content_posts
     WHERE status = 'approved' AND (publish_status = 'failed' OR publish_status IS NULL)"
)->fetchAll();
check('the retry cron cannot claim a post that is still waiting for its slot',
    !in_array((int) $scheduled['id'], array_map('intval', array_column($retryQueue, 'id')), true));
check('a future slot is not due yet', SocialPublishManager::due() === []);

// A caption stays the admin's to fix right up until it leaves.
$edited = SocialPublishManager::updateCaption(
    (int) $scheduled['id'],
    'Rewritten while queued #BeLiveSolopreneur',
    'timing-test-admin',
    (int) $scheduled['review_version']
);
check('a queued post can still be rewritten before its slot',
    $edited['caption'] === 'Rewritten while queued #BeLiveSolopreneur' && $edited['status'] === 'scheduled');

// ---- guard rails -----------------------------------------------------------
$rejectedTime = static function (string $when) use ($edited): string {
    try {
        SocialPublishManager::schedule((int) $edited['id'], 'timing-test-admin', (int) $edited['review_version'], $when, 'custom');
        return '';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
};
check('a time in the past is refused',
    str_contains($rejectedTime((new DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s')), 'at least'));
check('a time beyond the scheduling window is refused',
    str_contains($rejectedTime((new DateTimeImmutable('+90 days'))->format('Y-m-d H:i:s')), 'Schedule within'));
check('a time that is not a time is refused',
    $rejectedTime('whenever you like') !== '');

$staleVersion = '';
try {
    SocialPublishManager::schedule((int) $edited['id'], 'other-admin', (int) $edited['review_version'] - 1, $slot->format('Y-m-d H:i:s'), 'suggested');
} catch (Throwable $e) {
    $staleVersion = $e->getMessage();
}
check('scheduling from a stale page is refused, same as approving from one',
    str_contains($staleVersion, 'changed') || str_contains($staleVersion, 'reviewed by someone else'), $staleVersion);

// ---- unscheduling ----------------------------------------------------------
$backToDraft = SocialPublishManager::unschedule((int) $edited['id'], 'timing-test-admin', (int) $edited['review_version']);
check('cancelling a schedule returns the post to drafts with the slot cleared',
    $backToDraft['status'] === 'draft' && $backToDraft['scheduled_for'] === null && $backToDraft['schedule_source'] === null);
check('cancelling published nothing', $backToDraft['publish_status'] === null);

// ---- the slot arriving -----------------------------------------------------
// Backdate a scheduled post to make it due, exactly as time passing would.
// Facebook, because it is the one platform that publishes a caption with no
// media — the point here is the timing, not the media pipeline.
$dueDraft = $makeDraft('facebook');
$dueScheduled = SocialPublishManager::schedule(
    (int) $dueDraft['id'],
    'timing-test-admin',
    (int) $dueDraft['review_version'],
    PostTimingAdvisor::now()->modify('+2 hours')->format('Y-m-d H:i:s'),
    'suggested'
);
Database::run("UPDATE content_posts SET scheduled_for = (NOW() - INTERVAL 3 MINUTE) WHERE id = ?", [(int) $dueScheduled['id']]);

$due = SocialPublishManager::due();
check('a post whose slot has passed turns up in the publisher queue',
    count($due) === 1 && (int) $due[0]['id'] === (int) $dueScheduled['id']);

// What cron/publish_scheduled.php does with it.
$published = SocialPublishManager::approveAndPublish((int) $due[0]['id'], 'scheduler', (int) $due[0]['review_version']);
check('the due post publishes without an admin present',
    $published['status'] === 'posted' && in_array($published['publish_status'], ['published', 'simulated'], true),
    "{$published['status']}/{$published['publish_status']}");
check('the publish is stamped with a time and an id',
    $published['posted_at'] !== null && $published['external_post_id'] !== null);
check('the slot it was published for is kept on the record',
    $published['scheduled_for'] !== null && $published['scheduled_by'] === 'timing-test-admin');
check('the queue empties once the post has gone out', SocialPublishManager::due() === []);

// A second cron run overlapping the first must not re-post the same row.
$doublePost = '';
try {
    SocialPublishManager::approveAndPublish((int) $due[0]['id'], 'scheduler', (int) $due[0]['review_version']);
} catch (Throwable $e) {
    $doublePost = $e->getMessage();
}
check('an overlapping publisher run cannot post the same slot twice', $doublePost !== '', $doublePost);

// ---- audit -----------------------------------------------------------------
$logged = Database::run(
    "SELECT action FROM ai_activity_log WHERE action IN ('content_scheduled', 'content_unscheduled')"
)->fetchAll();
check('scheduling and unscheduling are both written to the audit trail',
    count(array_unique(array_column($logged, 'action'))) === 2);
