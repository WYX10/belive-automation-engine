<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

/**
 * Content module — AI-generated social post drafts from live room metrics.
 * Fully automated pipeline: drafts arrive on demand (button below) or once a day
 * on the schedule this page owns (App\Content\AutoDrafter, started by the tick
 * below, the cron file, or an outside scheduler); admin approval publishes
 * straight to the platform via SocialPublishManager, with a dry-run fallback
 * badged 'simulated' when no platform credential is active.
 */

use App\AI\Skills\CreateSkill;
use App\Content\AutoDrafter;
use App\Content\AiVideoJobs;
use App\Content\MascotLibrary;
use App\Content\PhotoPostDrafter;
use App\Content\PostTimingAdvisor;
use App\Content\PostingSchedule;
use App\Content\ContentPublishWorker;
use App\Content\PromoVideoDrafter;
use App\Content\RoomVideoComposer;
use App\Core\Auth;
use App\Core\Scheduler;
use App\Integrations\Social\SocialPublishManager;
use App\Integrations\WhatsApp\WhatsAppLink;
use App\Models\Room;
use App\Core\Database;
use App\Core\Settings;

require dirname(__DIR__) . '/_layout.php';
require __DIR__ . '/_timing.php';
Auth::requireAdmin();

// The stop / start controls for the daily run. Pausing leaves everything else
// alone — Generate and Run now still work, nothing just happens on its own.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'automation') {
    Auth::requireCsrf();
    $by = (string) ($_SESSION['admin_username'] ?? 'admin');

    if (($_POST['action'] ?? '') === 'run') {
        // Drafting is a handful of model calls (a reel is more) — the admin
        // asked for it and is waiting, so let it take the time it needs.
        @set_time_limit(0);

        try {
            $run = AutoDrafter::runNow('admin:' . $by);

            if ($run['aborted'] !== null) {
                set_flash('danger', 'Run stopped — ' . $run['aborted']);
            } elseif ($run['drafted'] === 0) {
                set_flash('warning', 'Nothing to draft — ' . implode(' · ', $run['lines'] ?: ['every available room already has a pending post']));
            } else {
                set_flash('success', "Drafted {$run['drafted']} post(s) now — " . implode(' · ', $run['lines'])
                    . '. They are waiting for your approval below.');
            }
        } catch (\Throwable $e) {
            set_flash('danger', 'Run failed: ' . $e->getMessage());
        }
    } else {
        $resume = ($_POST['action'] ?? '') === 'resume';
        AutoDrafter::setEnabled($resume, $by);
        set_flash(
            'success',
            $resume
                ? 'Daily drafting is back on — next run ' . AutoDrafter::nextSlot()->format('D j M, H:i') . '.'
                : 'Daily drafting stopped — nothing will be drafted on its own until you start it again.'
        );
    }

    header('Location: /admin/content');
    exit;
}

// Automation controls: drafts per cron run, platforms covered, standing brief.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'settings') {
    Auth::requireCsrf();

    $max = max(1, min(20, (int) ($_POST['content_auto_max'] ?? 3)));
    $hour = max(0, min(23, (int) ($_POST['content_auto_hour'] ?? AutoDrafter::DEFAULT_HOUR)));
    $platforms = array_values(array_intersect((array) ($_POST['content_auto_platforms'] ?? []), CONTENT_PLATFORMS));
    $brief = trim((string) ($_POST['content_brief'] ?? ''));
    $mediaKind = in_array($_POST['content_auto_media'] ?? '', CONTENT_MEDIA_KINDS, true) ? $_POST['content_auto_media'] : 'image';
    $postingTime = trim((string) ($_POST['content_publish_time'] ?? '18:00'));

    if ($platforms === []) {
        set_flash('danger', 'Pick at least one platform for the daily drafts.');
    } elseif (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $postingTime)) {
        set_flash('danger', 'Choose a valid posting time between 00:00 and 23:59.');
    } elseif ($mediaKind === 'video' && !RoomVideoComposer::isAvailable()) {
        set_flash('danger', 'Daily reels need ffmpeg on this server — install it (or set FFMPEG_BIN in .env) before scheduling video drafts.');
    } else {
        $by = (string) ($_SESSION['admin_username'] ?? 'admin');
        Settings::set('content_auto_max', (string) $max, $by);
        AutoDrafter::setHour($hour, $by);
        Settings::set('content_auto_platforms', implode(',', $platforms), $by);
        Settings::set('content_auto_media', $mediaKind, $by);
        Settings::set('content_brief', mb_substr($brief, 0, 1000), $by);
        Settings::set('content_wa_prefill', mb_substr(trim((string) ($_POST['content_wa_prefill'] ?? '')), 0, 200), $by);
        // What the preview page pre-selects when an admin approves a draft.
        Settings::set('content_schedule_default', in_array($_POST['content_schedule_default'] ?? '', ['now', 'fixed', 'suggested'], true) ? $_POST['content_schedule_default'] : 'suggested', $by);
        Settings::set('content_publish_time', PostingSchedule::validateTime($postingTime), $by);
        Settings::set('content_auto_publish_enabled', isset($_POST['content_auto_publish_enabled']) ? '1' : '0', $by);
        set_flash('success', "Automation saved — up to $max " . ($mediaKind === 'video' ? 'reel' : 'caption')
            . '(s) a day from ' . sprintf('%02d:00', $hour) . ' across ' . implode(', ', $platforms) . '.');
    }
    header('Location: /admin/content');
    exit;
}

// Generate a new draft on demand (real CreateSkill call on live room data).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'generate') {
    Auth::requireCsrf();

    $room = Room::find((int) ($_POST['room_id'] ?? 0));
    $platform = in_array($_POST['platform'] ?? '', CONTENT_PLATFORMS, true) ? $_POST['platform'] : 'facebook';
    $mediaKind = in_array($_POST['media_kind'] ?? '', ['image', 'video', 'ai_video'], true) ? $_POST['media_kind'] : 'image';
    // Per-post steer wins; otherwise fall back to the standing brief.
    $brief = trim((string) ($_POST['brief'] ?? '')) ?: Settings::get('content_brief', '');

    if ($room === null) {
        set_flash('danger', 'Pick a room to feature.');
    } else {
        try {
            if ($mediaKind === 'ai_video') {
                $jobId = AiVideoJobs::enqueue($room, $platform, $brief);
                set_flash('success', "AI video request #$jobId is queued. Review the generated draft here when it is ready.");
            } elseif ($mediaKind === 'video') {
                $video = PromoVideoDrafter::draft($room, $platform, $brief);
                set_flash('success', sprintf(
                    'Promo video rendered from this room\'s own photos — %d scenes, %.1fs, scripted by %s.',
                    $video['scenes'],
                    $video['seconds'],
                    $video['model']
                ));
            } else {
                $photo = PhotoPostDrafter::draft($room, $platform, $brief);
                set_flash('success', 'Draft generated from live room metrics by ' . $photo['model'] . '.'
                    . ($photo['branded'] ? ' The mascot is on the photo that publishes — your original stays untouched.' : ''));
            }
        } catch (\Throwable $e) {
            set_flash('danger', 'Generation failed: ' . $e->getMessage());
        }
    }
    header('Location: /admin/content');
    exit;
}

// Traffic can catch up a missed daily creation slot as well as the persistent
// worker or outside scheduler. AutoDrafter claims each slot only once.
$tick = Scheduler::tickAutoDraft();

$filter = $_GET['status'] ?? 'all';
$filterWhere = match ($filter) {
    'draft'     => "WHERE p.status = 'draft'",
    'scheduled' => "WHERE p.status = 'scheduled'",
    'failed'    => "WHERE p.status = 'approved' AND (p.publish_status IS NULL OR p.publish_status IN ('failed', 'uncertain'))",
    'posted'    => "WHERE p.status = 'posted'",
    'rejected'  => "WHERE p.status = 'rejected'",
    default     => '',
};
if ($filterWhere === '') {
    $filter = 'all';
}

// The scheduled queue reads best in the order it will actually go out.
$order = $filter === 'scheduled' ? 'p.scheduled_for ASC' : 'p.id DESC';
$posts = Database::run(
    "SELECT p.*, r.name AS room_name, r.location AS area FROM content_posts p
     LEFT JOIN rooms r ON r.id = p.room_id $filterWhere ORDER BY $order LIMIT 100"
)->fetchAll();
$rooms = \App\Catalog\RoomRepository::filter(['tenure' => 'monthly'], 100);
$counts = SocialPublishManager::counts();

$autoMax = Settings::getInt('content_auto_max', 3);
$autoPlatforms = Settings::getList('content_auto_platforms', CONTENT_PLATFORMS);
$autoMedia = Settings::get('content_auto_media', 'image');
$postingTime = PostingSchedule::time();
$autoPublish = Settings::get('content_auto_publish_enabled', '0') === '1';
$publisherStatus = ContentPublishWorker::status();
// Read after the tick, so a run this page load just started shows as running.
$auto = AutoDrafter::status();
// Rendering is the one thing the studio cannot do on its own — say so up front
// rather than after an admin has waited on a failing Generate.
$videoReady = RoomVideoComposer::isAvailable();
$aiVideoReady = AiVideoJobs::available();
$aiVideoJobs = AiVideoJobs::recent();
$mascotReady = MascotLibrary::isAvailable();
$standingBrief = Settings::get('content_brief', '');
$waPrefill = Settings::get('content_wa_prefill', '');
// Exactly what a reader taps, built from a real room so the preview is honest.
$sampleWaLink = CreateSkill::captionWhatsappLink($rooms[0] ?? ['name' => 'a room', 'location' => 'KL'], 'facebook');
$waNumber = WhatsAppLink::number();

// Best time to post: one week of our own engagement data, per platform.
$heatPlatform = in_array($_GET['heat'] ?? '', CONTENT_PLATFORMS, true) ? $_GET['heat'] : null;
$heatmap = PostTimingAdvisor::heatmap($heatPlatform);
$suggestions = PostTimingAdvisor::suggestions($heatPlatform, 3, $heatmap);
$scheduleDefault = Settings::get('content_schedule_default', 'suggested');

$platformIcons = ['facebook' => '📘', 'instagram' => '📷', 'tiktok' => '🎵'];
$filterTabs = [
    'all'       => "All ({$counts['all']})",
    'draft'     => "Awaiting approval ({$counts['pending']})",
    'scheduled' => "Scheduled ({$counts['scheduled']})",
    'failed'    => "Needs publish ({$counts['failed']})",
    'posted'    => "Posted ({$counts['posted']})",
    'rejected'  => "Rejected ({$counts['rejected']})",
];

admin_header('Content', 'content');
?>
<div class="belive-page-head">
    <h1>Content studio</h1>
    <span class="belive-muted" style="font-size:13px">Room metrics in → on-brand captions out. Approve a draft to publish it now, or schedule it for the slot the data likes best and it goes out on its own (dry-run when no credential is live).</span>
</div>

<div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px">
    <?php foreach ($filterTabs as $key => $label): ?>
        <a class="<?= $filter === $key ? 'belive-btn-secondary' : 'belive-btn-ghost' ?>" style="padding:6px 14px; font-size:13px"
           href="/admin/content<?= $key === 'all' ? '' : '?status=' . e($key) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<div class="belive-card" style="margin-bottom:16px">
    <div class="belive-card-title">🕒 Best time to post — one week</div>
    <p class="belive-muted" style="font-size:13px; margin-top:-4px">
        When BeLive's audience is actually there, hour by hour, from comments and DMs on our posts, new enquiries,
        and inbound messages. Pick a slot when you approve a draft and
        <code>cron/publish_scheduled.php</code> posts it at that minute — you do not have to be awake for it.
    </p>

    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px">
        <?php foreach (['' => '🌐 All channels'] + array_combine(CONTENT_PLATFORMS, array_map(
            static fn (string $p): string => $platformIcons[$p] . ' ' . ucfirst($p),
            CONTENT_PLATFORMS
        )) as $key => $label): ?>
            <a class="<?= ($heatPlatform ?? '') === $key ? 'belive-btn-secondary' : 'belive-btn-ghost' ?>"
               style="padding:5px 12px; font-size:12.5px"
               href="/admin/content?<?= http_build_query(array_filter(['status' => $filter === 'all' ? null : $filter, 'heat' => $key ?: null])) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>

    <?php timing_heatmap($heatmap, $suggestions); ?>

    <div style="margin-top:14px">
        <div style="font-size:13px; font-weight:600; margin-bottom:8px">
            Top <?= count($suggestions) ?> slots<?= $heatPlatform !== null ? ' for ' . e(ucfirst($heatPlatform)) : '' ?>
        </div>
        <div class="timing-picks">
            <?php foreach ($suggestions as $rank => $slot): ?>
                <div class="timing-pick" style="cursor:default">
                    <strong>#<?= $rank + 1 ?> · <?= e($slot['label']) ?></strong>
                    <div class="timing-pick-when">Next: <?= e($slot['next_label']) ?></div>
                    <div class="timing-pick-why"><?= e($slot['why']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <p class="belive-muted" style="font-size:12px; margin-top:12px; margin-bottom:0">
        <?= e(timing_basis($heatmap)) ?>
        An engagement at 21:00 also credits 20:00 at half weight — a post has to already be in the feed to be reacted to,
        so the advisor leans slightly ahead of the raw peak. All times Asia/Kuala_Lumpur.
    </p>
</div>

<div class="belive-card" style="margin-bottom:16px">
    <div class="belive-card-title">✨ Generate a post draft</div>
    <form method="post" action="/admin/content" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end">
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
        <input type="hidden" name="do" value="generate">
        <div class="belive-field" style="flex:2; min-width:220px; margin-bottom:0">
            <label>Room to feature</label>
            <select name="room_id">
                <?php foreach ($rooms as $room): ?>
                    <option value="<?= (int) $room['id'] ?>"><?= e($room['name']) ?> — <?= e($room['location']) ?>, RM<?= e(number_format((float) $room['price_at_tenure'])) ?>/mo flexible</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="belive-field" style="flex:1; min-width:140px; margin-bottom:0">
            <label>Platform</label>
            <select name="platform">
                <?php foreach (CONTENT_PLATFORMS as $platform): ?>
                    <option value="<?= e($platform) ?>"><?= e(ucfirst($platform)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="belive-field" style="flex:1; min-width:170px; margin-bottom:0">
            <label>Post type</label>
            <select name="media_kind">
                <option value="image">🖼 Photo post</option>
                <option value="video"<?= $videoReady ? '' : ' disabled' ?>>🎬 Animated room tour (9:16 reel)</option>
                <option value="ai_video"<?= $aiVideoReady ? '' : ' disabled' ?>>✨ AI-generated marketing video (Wan)</option>
            </select>
        </div>
        <div class="belive-field" style="flex:1 1 100%; margin-bottom:0">
            <label>What should this post be about? <span class="belive-muted" style="font-weight:400">(optional — blank uses the standing brief below)</span></label>
            <textarea name="brief" rows="2" maxlength="1000"
                      placeholder="e.g. push the zero-deposit angle for students moving in before September, mention the free weekly cleaning"></textarea>
        </div>
        <button type="submit" class="belive-btn-primary">Generate post</button>
        <div class="belive-muted" style="flex:1 1 100%; font-size:12.5px">
            <?php if ($aiVideoReady): ?>
                ✨ Wan generates a moving mascot introduction from the room photo. Generation runs in the background and depends on the free GPU allowance.
                Wan videos use a script from saved room details; edit custom caption wording in the draft.
                Review the room layout, mascot and claims before approving the draft.<br>
            <?php else: ?>
                ✨ Generative AI video needs the hosted Wan client enabled by IT. The animated room tour below uses local rendering.<br>
            <?php endif; ?>
            <?php if ($videoReady): ?>
                🎬 A promo video is cut from the room's <em>own</em> photos and tour clips — the AI writes the scenes and the
                caption, never the footage. Rendering takes a few seconds per scene, so give Generate a moment.<br>
            <?php else: ?>
                🎬 Promo video is unavailable — <code>ffmpeg</code> was not found on this server. Install it, or point
                <code>FFMPEG_BIN</code> in <code>.env</code> at the executable, and the option turns on.<br>
            <?php endif; ?>
            <?php if ($mascotReady): ?>
                🔒 The mascot hosts the room tour with animated gestures and spoken introductions. On a photo post it is
                placed into a touched-up campaign copy with ambient lighting and soft shadows. The room gallery keeps the original.
            <?php endif; ?>
            Every caption goes out with <code><?= e(CONTENT_REQUIRED_HASHTAG) ?></code> — added on the way out, so a draft
            can never lose the campaign tag.
        </div>
    </form>
</div>

<?php if ($aiVideoJobs !== []): ?>
<div class="belive-card" style="margin-bottom:16px">
    <div class="belive-card-title">AI video requests</div>
    <p class="belive-muted">Refresh this page to see progress. Generated videos become drafts and require your approval.</p>
    <?php foreach ($aiVideoJobs as $job): ?>
        <p>
            #<?= (int) $job['id'] ?> · Room #<?= (int) $job['room_id'] ?> · <?= e($job['platform']) ?> · <strong><?= e($job['status']) ?></strong>
            <?php if ($job['post_id']): ?>
                <a href="/admin/content/preview?id=<?= (int) $job['post_id'] ?>">Review generated video</a>
            <?php elseif ($job['error_code']): ?>
                — <?= e(AiVideoJobs::errorMessage($job['error_code'])) ?>
            <?php endif; ?>
        </p>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="belive-card" style="margin-bottom:16px">
    <div class="belive-card-title">🕒 Scheduled publishing</div>
    <p style="font-size:13px">
        <?= $publisherStatus['active'] ? '● Publisher has a recent heartbeat. Queued posts are checked by the background scheduler.' : 'Publisher has no recent heartbeat. Start the background scheduler or connect a recurring hosting task before relying on a posting time.' ?>
        All posting times use <strong>Asia/Kuala_Lumpur (UTC+8)</strong>.
    </p>
    <div style="display:flex; gap:10px; flex-wrap:wrap">
        <?php foreach (CONTENT_PLATFORMS as $platform):
            $connected = SocialPublishManager::publisherFor($platform)->isConfigured(); ?>
            <span class="belive-badge <?= $connected ? '' : 'orange' ?>"><?= e(ucfirst($platform)) ?> · <?= $connected ? 'connected' : 'connect account' ?></span>
        <?php endforeach; ?>
    </div>
    <p class="belive-muted" style="font-size:12px; margin-bottom:0">A disconnected account keeps its posts queued until it is connected. Successful delivery records the platform's post ID. <a href="/admin/credentials">Manage accounts</a></p>
</div>

<div class="belive-card" style="margin-bottom:16px">
    <div class="belive-card-title">⚙️ Daily automation</div>
    <p class="belive-muted" style="font-size:13px; margin-top:-4px">
        Once a day from <strong><?= sprintf('%02d:00', $auto['hour']) ?></strong> the AI drafts up to the limit below —
        currently <strong><?= (int) $autoMax ?> a day ≈ <?= (int) $autoMax * 7 ?> a week</strong>. Drafts still wait for
        your approval unless you enable automatic scheduling below. The background scheduler drafts once per day and delivers queued posts at their selected time, even when nobody opens this page.
    </p>

    <div style="display:flex; gap:14px; flex-wrap:wrap; align-items:center; justify-content:space-between;
                padding:12px 14px; margin-bottom:14px; border-radius:10px;
                background:var(--belive-teal-soft, #eef8f7)">
        <div style="display:flex; gap:14px; flex-wrap:wrap; align-items:center">
            <span class="belive-badge <?= $auto['enabled'] ? '' : 'orange' ?>" style="font-size:12.5px">
                <?= $auto['enabled'] ? '● Drafting daily' : '⏸ Stopped' ?>
            </span>
            <div style="font-size:13px">
                <?php if (!$auto['enabled']): ?>
                    <strong>Nothing is being drafted on its own.</strong>
                    <div class="belive-muted" style="font-size:12px">Press Start daily drafting to switch the schedule back on.</div>
                <?php elseif ($auto['running'] || ($tick !== null && $tick !== 'blocked')): ?>
                    <strong>Running now</strong> — today's drafts are being written.
                    <div class="belive-muted" style="font-size:12px">Reload this page in a moment and they will be in the list below.</div>
                <?php elseif ($tick === 'blocked'): ?>
                    <strong>Today's run is due, but this server will not let the panel start it.</strong>
                    <div class="belive-muted" style="font-size:12px">
                        Press Run now, or set <code>CRON_TOKEN</code> in <code>.env</code> and have an outside
                        scheduler call <code>/cron/auto_draft?token=…</code> daily.
                    </div>
                <?php else: ?>
                    <strong>Next run <?= e($auto['next_slot']->format('D j M, H:i')) ?></strong>
                    <div class="belive-muted" style="font-size:12px">
                        in <?= e((new DateTimeImmutable())->diff($auto['next_slot'])->format('%ad %hh %im')) ?>
                    </div>
                <?php endif; ?>
            </div>
            <div style="font-size:12.5px; max-width:420px">
                <?php if ($auto['last_finished'] !== null): ?>
                    <span class="belive-muted">Last run <?= e($auto['last_finished']->format('D j M, H:i')) ?><?php
                        $who = $auto['last_trigger'];
                        echo ' · ' . e(match (true) {
                            str_starts_with($who, 'admin:') => 'Run now, by ' . substr($who, 6),
                            $who === 'web'      => 'started by the panel',
                            $who === 'schedule' => 'started by the outside scheduler',
                            $who === 'cron'     => 'started from the command line',
                            default             => 'started by ' . $who,
                        });
                    ?></span>
                    <div><?= e($auto['last_result']) ?></div>
                <?php else: ?>
                    <span class="belive-muted">No run recorded yet — the first one will show here.</span>
                <?php endif; ?>
            </div>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap">
            <form method="post" action="/admin/content" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <input type="hidden" name="do" value="automation">
                <input type="hidden" name="action" value="<?= $auto['enabled'] ? 'pause' : 'resume' ?>">
                <button type="submit" class="<?= $auto['enabled'] ? 'belive-btn-ghost' : 'belive-btn-primary' ?>"
                        style="padding:8px 16px; font-size:13px">
                    <?= $auto['enabled'] ? '⏸ Stop daily drafting' : '▶ Start daily drafting' ?>
                </button>
            </form>
            <form method="post" action="/admin/content" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <input type="hidden" name="do" value="automation">
                <input type="hidden" name="action" value="run">
                <button type="submit" class="belive-btn-secondary" style="padding:8px 16px; font-size:13px"
                        title="Draft this run's posts right now, without waiting for the daily slot">
                    ⚡ Run now
                </button>
            </form>
        </div>
    </div>

    <form method="post" action="/admin/content" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-start">
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
        <input type="hidden" name="do" value="settings">
        <div class="belive-field" style="flex:0 0 130px; margin-bottom:0">
            <label>Drafts per day</label>
            <input type="number" name="content_auto_max" min="1" max="20" value="<?= (int) $autoMax ?>">
        </div>
        <div class="belive-field" style="flex:0 0 130px; margin-bottom:0">
            <label>Draft from</label>
            <select name="content_auto_hour">
                <?php for ($hour = 0; $hour < 24; $hour++): ?>
                    <option value="<?= $hour ?>"<?= $auto['hour'] === $hour ? ' selected' : '' ?>><?= sprintf('%02d:00', $hour) ?></option>
                <?php endfor; ?>
            </select>
            <div class="hint">Asia/Kuala_Lumpur</div>
        </div>
        <div class="belive-field" style="flex:0 0 180px; margin-bottom:0">
            <label>Draft type</label>
            <select name="content_auto_media">
                <option value="image"<?= $autoMedia === 'image' ? ' selected' : '' ?>>🖼 Photo posts</option>
                <option value="video"<?= $autoMedia === 'video' ? ' selected' : '' ?><?= $videoReady ? '' : ' disabled' ?>>🎬 Promo videos</option>
            </select>
        </div>
        <div class="belive-field" style="flex:0 0 240px; margin-bottom:0">
            <label>On approval, default to</label>
            <select name="content_schedule_default">
                <option value="suggested"<?= $scheduleDefault === 'suggested' ? ' selected' : '' ?>>🕒 Scheduling at the best slot</option>
                <option value="fixed"<?= $scheduleDefault === 'fixed' ? ' selected' : '' ?>>🕒 Daily posting time</option>
                <option value="now"<?= $scheduleDefault === 'now' ? ' selected' : '' ?>>⚡ Publishing immediately</option>
            </select>
            <div class="hint">Only what the preview page pre-selects — every post still goes out on the choice you confirm there.</div>
        </div>
        <div class="belive-field" style="flex:0 0 170px; margin-bottom:0">
            <label>Daily posting time</label>
            <input type="time" name="content_publish_time" value="<?= e($postingTime) ?>" required>
            <div class="hint">Asia/Kuala_Lumpur · UTC+8</div>
        </div>
        <div class="belive-field" style="flex:1 1 100%; margin-bottom:0">
            <label style="display:flex; gap:8px; align-items:center">
                <input type="checkbox" name="content_auto_publish_enabled" value="1"<?= $autoPublish ? ' checked' : '' ?>>
                Automatically schedule daily AI posts
            </label>
            <div class="hint">New daily drafts are approved for the posting time above. If that time has passed, they are queued for tomorrow. Leave this off to review each draft yourself.</div>
        </div>
        <div class="belive-field" style="flex:1; min-width:200px; margin-bottom:0">
            <label>Platforms</label>
            <div style="display:flex; gap:14px; flex-wrap:wrap; padding-top:6px">
                <?php foreach (CONTENT_PLATFORMS as $platform): ?>
                    <label style="font-weight:400; display:flex; gap:6px; align-items:center">
                        <input type="checkbox" name="content_auto_platforms[]" value="<?= e($platform) ?>"
                            <?= in_array($platform, $autoPlatforms, true) ? 'checked' : '' ?>>
                        <?= $platformIcons[$platform] ?> <?= e(ucfirst($platform)) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="belive-field" style="flex:1 1 100%; margin-bottom:0">
            <label>Standing content brief <span class="belive-muted" style="font-weight:400">(what the AI should write about by default)</span></label>
            <textarea name="content_brief" rows="3" maxlength="1000"
                      placeholder="e.g. lead with zero deposit and fully furnished; keep it upbeat for young professionals in KL; always mention flexible monthly tenure"><?= e($standingBrief) ?></textarea>
        </div>
        <div class="belive-field" style="flex:1 1 100%; margin-bottom:0">
            <label>WhatsApp call to action <span class="belive-muted" style="font-weight:400">(the message already typed when a reader taps the link — <code>{room}</code>, <code>{area}</code>, <code>{platform}</code> are filled in)</span></label>
            <input type="text" name="content_wa_prefill" value="<?= e($waPrefill) ?>" maxlength="200"
                   placeholder="Hi beLive! I saw your {platform} post about {room} in {area} — is it still available?">
            <div class="hint">
                Every AI caption ends with this link: <code><?= e($sampleWaLink) ?></code>
                <?php if ($waNumber === ''): ?>
                    — no WhatsApp number set yet, so captions fall back to the shared link and lose the prefill.
                    Set it in <a href="/admin/social">Social replies</a>.
                <?php endif; ?>
            </div>
        </div>
        <button type="submit" class="belive-btn-secondary">Save automation</button>
    </form>
</div>

<div class="belive-card">
    <?php if ($posts === []): ?>
        <p class="belive-muted"><?= match ($filter) {
            'all'       => 'No drafts yet — generate one above, or let the daily cron draft for you.',
            'scheduled' => 'Nothing is queued. Approve a draft and pick one of the suggested slots to line a post up here.',
            default     => 'Nothing in this state right now.',
        } ?></p>
    <?php else: ?>
        <div class="belive-table-wrap">
            <table class="belive-table">
                <thead><tr><th>Platform</th><th>Type</th><th>Room</th><th>Caption</th><th>Model</th><th>Goes out</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($posts as $post): ?>
                    <tr>
                        <td style="white-space:nowrap"><?= $platformIcons[$post['platform']] ?> <?= e(ucfirst($post['platform'])) ?></td>
                        <td style="white-space:nowrap; font-size:13px"><?= $post['media_kind'] === 'video' ? '🎬 Video' : '🖼 Photo' ?></td>
                        <td style="font-size:13px"><?= e($post['room_name'] ? "{$post['room_name']} ({$post['area']})" : '—') ?></td>
                        <td style="font-size:13px; max-width:320px"><?= e(mb_substr($post['caption'], 0, 120)) ?><?= mb_strlen($post['caption']) > 120 ? '…' : '' ?></td>
                        <td style="font-size:12.5px"><code><?= e($post['generated_by_model']) ?></code></td>
                        <td style="font-size:12.5px; white-space:nowrap">
                            <?php if ($post['status'] === 'scheduled' && $post['scheduled_for']): ?>
                                <?php $slot = new DateTimeImmutable((string) $post['scheduled_for']); ?>
                                🕒 <?= e($slot->format('D j M, H:i')) ?>
                                <div class="belive-muted" style="font-size:11px">
                                    <?= $slot <= new DateTimeImmutable() ? 'due — next publisher check' : 'in ' . e((new DateTimeImmutable())->diff($slot)->format('%ad %hh %im')) ?>
                                </div>
                            <?php elseif ($post['posted_at']): ?>
                                <?= e((new DateTimeImmutable((string) $post['posted_at']))->format('D j M, H:i')) ?>
                                <?php if ($post['scheduled_for']): ?>
                                    <div class="belive-muted" style="font-size:11px">scheduled for <?= e((new DateTimeImmutable((string) $post['scheduled_for']))->format('D j M, H:i')) ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="belive-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="belive-badge <?= match ($post['status']) { 'posted', 'scheduled' => '', 'rejected' => 'danger', default => 'orange' } ?>"><?= e($post['status']) ?></span>
                            <?php if ($post['publish_status']): ?>
                                <span class="belive-badge <?= match ($post['publish_status']) { 'published' => '', 'simulated', 'publishing' => 'orange', default => 'danger' } ?>" style="font-size:11px"><?= e($post['publish_status']) ?></span>
                            <?php endif; ?>
                            <?php if ($post['publish_error']): ?>
                                <div class="belive-muted" style="font-size:11px" title="<?= e($post['publish_error']) ?>"><?= e(mb_substr($post['publish_error'], 0, 60)) ?><?= mb_strlen($post['publish_error']) > 60 ? '…' : '' ?></div>
                            <?php endif; ?>
                        </td>
                        <td><a class="belive-btn-ghost" style="padding:5px 12px; font-size:13px" href="/admin/content/preview?id=<?= (int) $post['id'] ?>">Preview</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php admin_footer();
