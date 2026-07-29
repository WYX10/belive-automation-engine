<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Content\PostTimingAdvisor;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Settings;
use App\Integrations\Social\SocialPublishManager;
use App\Integrations\WhatsApp\WhatsAppLink;

require dirname(__DIR__) . '/_layout.php';
require __DIR__ . '/_timing.php';
Auth::requireAdmin();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    $action = $_POST['do'] ?? '';
    $reviewer = $_SESSION['admin_username'] ?? 'admin';
    $version = (int) ($_POST['review_version'] ?? 0);

    try {
        if ($action === 'approve') {
            // One button, two outcomes — whichever the admin chose in the
            // "when" picker. Scheduling is an approval too: the caption is
            // signed off now, only the delivery waits.
            $when = (string) ($_POST['schedule_when'] ?? 'now');

            if ($when === 'now') {
                $post = SocialPublishManager::approveAndPublish($id, $reviewer, $version);
                match ($post['publish_status']) {
                    'published' => set_flash('success', "Approved and published to {$post['platform']} — post id {$post['external_post_id']}."),
                    'simulated' => set_flash('success', "Approved — publish simulated (no active {$post['platform']} credential; the exact payload is in the activity log)."),
                    default     => set_flash('danger', 'Approved, but the publish failed: ' . $post['publish_error'] . ' — fix the cause and hit Retry.'),
                };
            } else {
                // 'custom' takes the datetime field; anything else is one of
                // the advisor's slots, posted as a literal timestamp.
                $at = $when === 'custom' ? (string) ($_POST['schedule_custom'] ?? '') : $when;
                $post = SocialPublishManager::schedule($id, $reviewer, $version, $at, $when === 'custom' ? 'custom' : 'suggested');
                $slot = new DateTimeImmutable((string) $post['scheduled_for']);
                set_flash('success', "Scheduled for {$slot->format('D j M Y, H:i')} — it publishes to {$post['platform']} on its own, no one has to be at the desk.");
            }
        } elseif ($action === 'unschedule') {
            SocialPublishManager::unschedule($id, $reviewer, $version);
            set_flash('success', 'Taken off the schedule — it is back in drafts and nothing was published.');
        } elseif ($action === 'reject') {
            SocialPublishManager::reject($id, $reviewer, $_POST['review_note'] ?? '', $version);
            set_flash('success', 'Draft rejected — it will not be published.');
        } elseif ($action === 'edit') {
            SocialPublishManager::updateCaption($id, (string) ($_POST['caption'] ?? ''), $reviewer, $version);
            set_flash('success', 'Caption saved — this is exactly what will be published.');
        } elseif ($action === 'retry') {
            $post = SocialPublishManager::retryPublish($id, $reviewer, $version);
            match ($post['publish_status']) {
                'published' => set_flash('success', "Published to {$post['platform']} — post id {$post['external_post_id']}."),
                'simulated' => set_flash('success', "Publish simulated (no active {$post['platform']} credential; payload logged)."),
                default     => set_flash('danger', 'Publish failed again: ' . $post['publish_error']),
            };
        }
    } catch (\Throwable $e) {
        set_flash('danger', $e->getMessage());
    }
    header("Location: /admin/content/preview?id=$id");
    exit;
}

$post = Database::run(
    'SELECT p.*, r.name AS room_name, r.location AS area, rp.price FROM content_posts p
     LEFT JOIN rooms r ON r.id = p.room_id
     LEFT JOIN room_pricing rp ON rp.room_id = r.id AND rp.tenure = \'monthly\'
     WHERE p.id = ?',
    [$id]
)->fetch();

if ($post === false) {
    set_flash('danger', 'Draft not found.');
    header('Location: /admin/content');
    exit;
}

$imageUrl = SocialPublishManager::resolveImageUrl($post);
$isVideo = ($post['media_kind'] ?? 'image') === 'video';
$isBranded = str_starts_with((string) ($post['image_url'] ?? ''), '/assets/img/uploads/branded/');
// The player streams from our own site, so it wants the site-local path — the
// absolute APP_URL form is for the platforms fetching it, not for this page.
$videoPath = $isVideo ? (string) ($post['video_url'] ?? '') : '';
$scenes = $isVideo ? (json_decode((string) ($post['video_script'] ?? ''), true) ?: []) : [];
$videoSeconds = array_sum(array_map(static fn (array $scene): float => (float) ($scene['seconds'] ?? 0), $scenes));

// A caption is the admin's to rewrite right up until it leaves for the
// platform; after that the platform holds the copy.
$editable = !in_array($post['status'], ['posted', 'rejected'], true)
    && !in_array($post['publish_status'], ['published', 'simulated'], true);
$hasWhatsAppLink = str_contains($post['caption'], 'wa.me/') || str_contains($post['caption'], 'wa.link/');

// The timing advisor, narrowed to the platform this post is actually going to.
$heatmap = PostTimingAdvisor::heatmap($post['platform']);
$suggestions = PostTimingAdvisor::suggestions($post['platform'], 3, $heatmap);
$scheduleDefault = Settings::get('content_schedule_default', 'suggested');
$now = PostTimingAdvisor::now();
$scheduledAt = $post['scheduled_for'] ? new DateTimeImmutable((string) $post['scheduled_for'], new DateTimeZone(PostTimingAdvisor::TIMEZONE)) : null;
$decidable = in_array($post['status'], ['draft', 'scheduled'], true);
// The bounds the manager enforces server-side, mirrored into the date field so
// the browser rejects an impossible time before the round trip.
$customMin = $now->modify('+' . CONTENT_SCHEDULE_MIN_LEAD_MINUTES . ' minutes')->format('Y-m-d\TH:i');
$customMax = $now->modify('+' . CONTENT_SCHEDULE_MAX_DAYS . ' days')->format('Y-m-d\TH:i');

$statusBadge = match ($post['status']) {
    'posted', 'scheduled' => '',
    'rejected'            => 'danger',
    default               => 'orange',
};
$publishBadge = match ($post['publish_status']) {
    'published' => '',
    'simulated' => 'orange',
    'failed'    => 'danger',
    default     => null,
};

admin_header('Post preview', 'content');
?>
<div class="belive-page-head">
    <h1>Post preview</h1>
    <a class="belive-btn-ghost" href="/admin/content">← Content studio</a>
</div>

<div class="belive-row">
    <div class="belive-col" style="max-width:520px">
        <div class="belive-card">
            <div class="belive-card-title">
                <?= ['facebook' => '📘', 'instagram' => '📷', 'tiktok' => '🎵'][$post['platform']] ?>
                <?= e(ucfirst($post['platform'])) ?> <?= $isVideo ? 'video draft' : 'draft' ?>
                <span class="belive-badge <?= $statusBadge ?>"><?= e($post['status']) ?></span>
                <?php if ($publishBadge !== null): ?>
                    <span class="belive-badge <?= $publishBadge ?>"><?= e($post['publish_status']) ?></span>
                <?php endif; ?>
            </div>

            <?php if ($isVideo && $videoPath !== ''): ?>
                <video src="<?= e($videoPath) ?>" controls playsinline preload="metadata"
                       <?= $imageUrl !== null ? 'poster="' . e($imageUrl) . '"' : '' ?>
                       style="width:100%; max-width:300px; border-radius:12px; margin-bottom:10px; background:#000; display:block"></video>
                <div class="belive-muted" style="font-size:12.5px; margin-bottom:10px">
                    This is exactly the file that gets published — 9:16, <?= e(number_format($videoSeconds, 1)) ?>s,
                    cut from this room's own photos and tour clips.
                    <a href="<?= e($videoPath) ?>" download>Download</a>
                </div>
            <?php elseif ($isVideo): ?>
                <div class="belive-badge danger" style="margin-bottom:10px; white-space:normal">
                    This video draft has no rendered file — publishing it will fail. Generate it again from the content studio.
                </div>
            <?php elseif ($imageUrl !== null): ?>
                <img src="<?= e($imageUrl) ?>" alt="Attached room photo" style="width:100%; border-radius:12px; margin-bottom:10px; max-height:260px; object-fit:cover">
                <?php if ($isBranded): ?>
                    <div class="belive-muted" style="font-size:12.5px; margin-bottom:10px">
                        🔒 Mascot-branded copy — this is what publishes. The room's gallery photo is untouched.
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="belive-muted" style="font-size:12.5px; margin-bottom:10px">No room photo attached — Facebook posts text-only; Instagram and TikTok need an image.</div>
            <?php endif; ?>

            <div style="background:var(--belive-cream); border-radius:12px; padding:16px; white-space:pre-wrap; font-size:14px; line-height:1.6"><?= e($post['caption']) ?></div>

            <div class="belive-muted" style="font-size:12.5px; margin-top:8px">
                <?= $hasWhatsAppLink
                    ? '💬 Carries a WhatsApp link — readers tap straight into a chat with Eve.'
                    : '⚠ No WhatsApp link in this caption. Add one so readers can reach Eve in one tap: ' . e(WhatsAppLink::to()) ?>
            </div>

            <?php if ($editable): ?>
                <details class="content-caption-edit"<?= $post['status'] === 'approved' ? ' open' : '' ?>>
                    <summary class="belive-btn-ghost">✏️ Edit caption before it posts</summary>
                    <form method="post" style="margin-top:12px">
                        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="review_version" value="<?= (int) $post['review_version'] ?>">
                        <input type="hidden" name="do" value="edit">
                        <div class="belive-field" style="margin-bottom:8px">
                            <label for="caption">Caption <span class="belive-muted" style="font-weight:400">(this exact text is what gets published)</span></label>
                            <textarea id="caption" name="caption" rows="10" maxlength="<?= CONTENT_CAPTION_MAX ?>" required><?= e($post['caption']) ?></textarea>
                            <div class="hint">Keep the WhatsApp link in — it is the call to action that turns a reader into a lead.</div>
                        </div>
                        <button type="submit" class="belive-btn-secondary">Save caption</button>
                    </form>
                </details>
            <?php endif; ?>

            <div class="belive-muted" style="font-size:12.5px; margin-top:10px">
                Generated by <code><?= e($post['generated_by_model']) ?></code>
                (<?= e($post['generated_via'] ?? 'manual') ?>)
                from <?= e($post['room_name'] ? "{$post['room_name']} — {$post['area']}, RM" . number_format((float) $post['price']) . '/mo' : 'room data') ?>
                · <?= e($post['created_at']) ?>
            </div>

            <?php if ($post['reviewed_by']): ?>
                <div class="belive-muted" style="font-size:12.5px; margin-top:4px">
                    Reviewed by <?= e($post['reviewed_by']) ?> · <?= e($post['reviewed_at']) ?>
                    <?php if ($post['review_note']): ?> — “<?= e($post['review_note']) ?>”<?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($post['publish_status'] === 'failed'): ?>
                <div class="belive-badge danger" style="margin-top:10px; white-space:normal">Publish failed: <?= e($post['publish_error']) ?></div>
            <?php elseif ($post['external_post_id']): ?>
                <div class="belive-muted" style="font-size:12.5px; margin-top:4px">
                    <?= $post['publish_status'] === 'simulated' ? 'Simulated post id' : 'Platform post id' ?>:
                    <code><?= e($post['external_post_id']) ?></code> · <?= e($post['posted_at']) ?>
                </div>
            <?php endif; ?>

            <?php if ($scheduledAt !== null): ?>
                <div class="belive-muted" style="font-size:12.5px; margin-top:4px">
                    🕒 <?= $post['status'] === 'scheduled' ? 'Scheduled for' : 'Was scheduled for' ?>
                    <?= e($scheduledAt->format('D j M Y, H:i')) ?>
                    by <?= e((string) ($post['scheduled_by'] ?? 'admin')) ?>
                    (<?= e((string) ($post['schedule_source'] ?? 'custom')) ?> slot)
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="belive-col">
        <?php if ($decidable): ?>
            <div class="belive-card" style="margin-bottom:16px">
                <div class="belive-card-title"><?= $post['status'] === 'scheduled' ? '🕒 Scheduled' : '✅ Approve — now or at the best time' ?></div>

                <?php if ($post['status'] === 'scheduled' && $scheduledAt !== null): ?>
                    <p style="font-size:13.5px; margin-top:-4px">
                        This post goes out to <strong><?= e(ucfirst($post['platform'])) ?></strong> on
                        <strong><?= e($scheduledAt->format('l j F, H:i')) ?></strong>
                        <?= $scheduledAt <= $now
                            ? '— due now, the publisher cron takes it on its next run (every 5 minutes).'
                            : '— that is ' . e($now->diff($scheduledAt)->format('%a day(s), %h hour(s) and %i minute(s)')) . ' from now.' ?>
                        Nobody needs to be at the desk for it. You can still edit the caption until it leaves.
                    </p>
                    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="hidden" name="review_version" value="<?= (int) $post['review_version'] ?>">
                            <input type="hidden" name="do" value="approve">
                            <input type="hidden" name="schedule_when" value="now">
                            <button type="submit" class="belive-btn-secondary" data-confirm="Publish this post right now instead of waiting for its slot?">Publish now instead</button>
                        </form>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="hidden" name="review_version" value="<?= (int) $post['review_version'] ?>">
                            <input type="hidden" name="do" value="unschedule">
                            <button type="submit" class="belive-btn-ghost">Cancel schedule</button>
                        </form>
                    </div>
                    <div class="belive-card-title" style="font-size:14px">Move it to a different time</div>
                <?php endif; ?>

                <p class="belive-muted" style="font-size:12.5px; margin-top:-4px">
                    Ringed slots below are when <?= e(ucfirst($post['platform'])) ?>'s audience has actually been engaging.
                    Pick one and the post publishes itself at that minute.
                </p>

                <?php timing_heatmap($heatmap, $suggestions); ?>
                <p class="belive-muted" style="font-size:11.5px; margin-top:8px; margin-bottom:0"><?= e(timing_basis($heatmap)) ?></p>

                <form method="post" style="margin-top:14px" id="schedule-form">
                    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="review_version" value="<?= (int) $post['review_version'] ?>">
                    <input type="hidden" name="do" value="approve">

                    <div class="timing-picks" style="margin-bottom:10px">
                        <?php foreach ($suggestions as $rank => $slot):
                            $value = $slot['next']->format('Y-m-d H:i:s');
                            $checked = $rank === 0 && $scheduleDefault !== 'now' && $post['status'] === 'draft';
                            ?>
                            <label class="timing-pick">
                                <input type="radio" name="schedule_when" value="<?= e($value) ?>"
                                       data-label="Approve &amp; schedule for <?= e($slot['next_label']) ?>"<?= $checked ? ' checked' : '' ?>>
                                <strong>#<?= $rank + 1 ?> · <?= e($slot['label']) ?></strong>
                                <div class="timing-pick-when"><?= e($slot['next_label']) ?></div>
                                <div class="timing-pick-why"><?= e($slot['why']) ?></div>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="timing-picks">
                        <label class="timing-pick">
                            <input type="radio" name="schedule_when" value="now" data-label="Approve &amp; publish now"
                                <?= $scheduleDefault === 'now' || $post['status'] === 'scheduled' ? ' checked' : '' ?>>
                            <strong>⚡ Publish now</strong>
                            <div class="timing-pick-why">Goes out the moment you approve.</div>
                        </label>
                        <label class="timing-pick">
                            <input type="radio" name="schedule_when" value="custom" data-label="Approve &amp; schedule">
                            <strong>📅 A time I choose</strong>
                            <input type="datetime-local" name="schedule_custom" style="margin-top:6px"
                                   min="<?= e($customMin) ?>" max="<?= e($customMax) ?>"
                                   value="<?= e(($scheduledAt ?? $suggestions[0]['next'] ?? $now)->format('Y-m-d\TH:i')) ?>">
                            <div class="timing-pick-why">Within the next <?= CONTENT_SCHEDULE_MAX_DAYS ?> days.</div>
                        </label>
                    </div>

                    <button type="submit" class="belive-btn-primary" style="margin-top:14px" id="schedule-submit">
                        <?= $post['status'] === 'scheduled' ? 'Reschedule' : 'Approve this post' ?>
                    </button>
                    <div class="belive-muted" style="font-size:12px; margin-top:6px">
                        Approving signs off the caption. Only the delivery waits —
                        <code>cron/publish_scheduled.php</code> publishes the queue every five minutes,
                        and a slot that fails falls through to the existing retry job rather than being lost.
                    </div>
                </form>

                <form method="post" style="margin-top:16px; border-top:1px solid var(--belive-line); padding-top:14px">
                    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="review_version" value="<?= (int) $post['review_version'] ?>">
                    <input type="hidden" name="do" value="reject">
                    <div class="belive-field" style="margin-bottom:8px">
                        <label for="review_note">Rejection reason</label>
                        <textarea id="review_note" name="review_note" rows="2" maxlength="500" placeholder="Why this draft shouldn't go out — helps the next generation improve."></textarea>
                    </div>
                    <button type="submit" class="belive-btn-ghost" data-confirm="Reject this draft? It will never be published.">Reject draft</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($post['status'] === 'approved' && in_array($post['publish_status'], ['failed', null], true)): ?>
            <div class="belive-card" style="margin-bottom:16px">
                <div class="belive-card-title"><?= $post['publish_status'] === 'failed' ? '⚠ Publish failed' : '⏳ Approved, not yet published' ?></div>
                <p class="belive-muted" style="font-size:13px; margin-top:-4px">
                    <?= $post['publish_status'] === 'failed'
                        ? 'cron/publish_retry.php will try again on its own every 30 minutes — this button skips the wait.'
                        : 'This post was approved but its publish never ran.' ?>
                </p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="review_version" value="<?= (int) $post['review_version'] ?>">
                    <input type="hidden" name="do" value="retry">
                    <button type="submit" class="belive-btn-secondary"><?= $post['publish_status'] === 'failed' ? 'Retry publish' : 'Publish now' ?></button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($isVideo && $scenes !== []): ?>
            <div class="belive-card" style="margin-bottom:16px">
                <div class="belive-card-title">🎬 Shot list</div>
                <p class="belive-muted" style="font-size:13px; margin-top:-4px">
                    What the AI wrote onto each shot. The closing WhatsApp card is added by the studio, not the model —
                    every reel ends with a way to reach Eve.
                </p>
                <div class="belive-table-wrap">
                    <table class="belive-table">
                        <thead><tr><th>#</th><th>On screen</th><th style="white-space:nowrap">Length</th></tr></thead>
                        <tbody>
                        <?php foreach ($scenes as $index => $scene): ?>
                            <tr>
                                <td><?= $index + 1 ?><?= !empty($scene['cta']) ? ' 💬' : '' ?></td>
                                <td style="font-size:13px">
                                    <strong><?= e((string) ($scene['headline'] ?? '')) ?></strong>
                                    <?php if (($scene['sub'] ?? '') !== ''): ?>
                                        <div class="belive-muted"><?= e((string) $scene['sub']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space:nowrap; font-size:13px"><?= e(number_format((float) ($scene['seconds'] ?? 0), 1)) ?>s</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <div class="belive-card">
            <div class="belive-card-title">ℹ Publishing model</div>
            <p style="font-size:13.5px" class="belive-muted">
                The full pipeline is automated: AI drafts from live room metrics (on schedule and
                on demand), an admin approves here, and approval <strong>publishes straight to the
                platform</strong> — Facebook Page feed/photos, Instagram content publishing, and
                the TikTok Content Posting API. When a platform credential isn't active yet the
                publish runs in <strong>dry-run mode</strong>: the exact API payload is written to
                the activity log and the post is badged <em>simulated</em>, never passed off as a
                real delivery. Instagram and TikTok fetch the media by URL, so real publishing
                needs a public <code>APP_URL</code> and (for Meta) a Page token with
                <code>pages_manage_posts</code> + <code>instagram_content_publish</code>.
                A promo video takes the video route on each platform — Instagram as a
                <code>REELS</code> container, Facebook on the Page's video edge, TikTok through
                the video init endpoint — and Instagram transcodes it, so that publish can sit
                for a minute or two before it returns.
            </p>
        </div>
    </div>
</div>
<?php admin_footer();
