<?php

declare(strict_types=1);

/**
 * The daily content run — the half that used to depend on something outside the
 * app calling cron/auto_draft_content.php, which on App Service never happened.
 *
 * Four promises are under test:
 *   1. the run is owed once per day and taken exactly once, however many callers
 *      ask (page loads, the cron file, an outside scheduler),
 *   2. Stop really stops it — a paused schedule is never due,
 *   3. Run now drafts regardless, including while paused, because that is a
 *      person asking,
 *   4. "drafts per day" is a real limit: it rounds over the platforms instead of
 *      taking one each and stopping.
 */

use App\Content\AutoDrafter;
use App\Core\Database;
use App\Core\Settings;
use App\Properties\PropertyManager;
use App\Properties\PropertyReviewManager;

$autoBy = 'auto-draft-test';

// Enough rooms of our own that the limit, not the stock, is what stops a run.
$autoProperty = PropertyManager::addProperty('Auto Draft Test Owner', [
    'name' => 'Auto Draft Residence',
    'location' => 'Wangsa Maju',
    'address' => '45 Automation Road, Wangsa Maju',
]);
$autoProperty = PropertyReviewManager::review(
    (int) $autoProperty['id'],
    'approved',
    'auto-draft-admin',
    'Approved so the daily run has something to feature.',
    (int) $autoProperty['review_version']
);
for ($i = 1; $i <= 4; $i++) {
    PropertyManager::addRoomForAdmin((int) $autoProperty['id'], [
        'room_code' => "ADR-0$i",
        'name' => "Auto Draft Room $i",
        'room_type' => 'single',
        'status' => 'available',
        'price_monthly' => '690',
        'price_6_month' => '660',
        'price_12_month' => '640',
        'referral_reward_points' => '40',
    ]);
}

$draftedRows = static fn (): int => (int) Database::run('SELECT COUNT(*) FROM content_posts')->fetchColumn();
$viaRows = static fn (string $via): int => (int) Database::run(
    'SELECT COUNT(*) FROM content_posts WHERE generated_via = ?',
    [$via]
)->fetchColumn();

// The schedule's own state, as the app reads it. Cleared here so the assertions
// below are about this file's runs and not whatever the hour happens to be.
$releaseSlot = static fn () => Settings::set('content_auto_claimed_slot', '', $autoBy);

Settings::set('content_auto_platforms', 'facebook,instagram', $autoBy);
Settings::set('content_auto_max', '2', $autoBy);
Settings::set('content_auto_media', 'image', $autoBy);
AutoDrafter::setHour((int) (new DateTimeImmutable())->format('G'), $autoBy);
$releaseSlot();

// ---- 2. Stop means stopped -------------------------------------------------
AutoDrafter::setEnabled(false, $autoBy);
check('a stopped schedule is never due', AutoDrafter::isDue() === false);
check('a stopped schedule drafts nothing on a tick', AutoDrafter::runIfDue('test') === null);

$beforePausedManual = $draftedRows();
$pausedRun = AutoDrafter::runNow('test-manual');
check('Run now still drafts while the schedule is stopped — it is a person asking',
    $pausedRun['drafted'] > 0 && $draftedRows() > $beforePausedManual,
    "drafted={$pausedRun['drafted']}");

// ---- 1. owed once a day, taken once ----------------------------------------
AutoDrafter::setEnabled(true, $autoBy);
$releaseSlot();
check('a running schedule past its hour is due', AutoDrafter::isDue() === true);

$before = $draftedRows();
$beforeCronVia = $viaRows('cron');
$run = AutoDrafter::runIfDue('test');
check('the due run drafts up to the daily limit', $run !== null && $run['drafted'] === 2,
    'drafted=' . ($run['drafted'] ?? 'null'));
check('a run nobody asked for is recorded as written by the schedule',
    $viaRows('cron') === $beforeCronVia + 2,
    'cron rows +' . ($viaRows('cron') - $beforeCronVia));
check('every draft it reports is really in the studio', $draftedRows() === $before + 2,
    'rows=' . ($draftedRows() - $before));
check('the run records itself for the studio card',
    AutoDrafter::status()['last_drafted'] === 2
    && AutoDrafter::status()['last_trigger'] === 'test'
    && str_contains(AutoDrafter::status()['last_result'], '2 draft(s)'),
    AutoDrafter::status()['last_result']);

// The heart of it: every later caller that day is told no, without drafting.
$afterFirst = $draftedRows();
check('the slot is settled once taken — a second tick is not due', AutoDrafter::isDue() === false);
check('a second tick drafts nothing', AutoDrafter::runIfDue('test-again') === null);
check('three more ticks still draft nothing',
    AutoDrafter::runIfDue('test-3') === null
    && AutoDrafter::runIfDue('test-4') === null
    && AutoDrafter::runIfDue('test-5') === null
    && $draftedRows() === $afterFirst);

$tomorrow = (new DateTimeImmutable())->modify('+1 day')->format('Y-m-d');
check('the card now counts down to tomorrow\'s slot',
    AutoDrafter::nextSlot()->format('Y-m-d') === $tomorrow,
    AutoDrafter::nextSlot()->format('Y-m-d H:i'));

// ---- 3. Run now ignores the daily guard ------------------------------------
$beforeManual = $draftedRows();
$beforeManualVia = $viaRows('manual');
$manual = AutoDrafter::runNow('admin:tester');
check('Run now drafts even though today\'s slot is already settled',
    $manual['drafted'] === 2 && $draftedRows() === $beforeManual + 2,
    "drafted={$manual['drafted']}");
// Admin → Reports says the cron-written posts were made "without anyone asking",
// so a button press must not be counted among them.
check('a Run now draft is attributed to the admin, not to the schedule',
    $viaRows('manual') === $beforeManualVia + 2,
    'manual rows +' . ($viaRows('manual') - $beforeManualVia));

// ---- 4. the limit is a limit, not one per platform -------------------------
$beforeRound = $draftedRows();
$rounds = AutoDrafter::runNow('test-rounds', ['platforms' => ['facebook'], 'max' => 3]);
check('three drafts for one platform means three rounds, not one draft and a stop',
    $rounds['drafted'] === 3 && $draftedRows() === $beforeRound + 3,
    "drafted={$rounds['drafted']}");

// ---- a run that cannot do its job says so instead of reporting success ------
$noPlatforms = AutoDrafter::runNow('test-empty', ['platforms' => []]);
check('a run with no platforms selected aborts with a reason',
    $noPlatforms['drafted'] === 0 && $noPlatforms['aborted'] !== null,
    (string) $noPlatforms['aborted']);
check('the abort is what the studio card reports',
    str_starts_with(AutoDrafter::status()['last_result'], 'aborted —'),
    AutoDrafter::status()['last_result']);

// Leave the schedule as a fresh install would have it, so nothing downstream
// inherits a settled slot or a stopped schedule from this file.
AutoDrafter::setEnabled(true, $autoBy);
AutoDrafter::setHour(AutoDrafter::DEFAULT_HOUR, $autoBy);
$releaseSlot();
