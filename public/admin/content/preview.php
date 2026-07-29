<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Auth;
use App\Core\Database;
use App\Integrations\Social\SocialPublishManager;
use App\Integrations\WhatsApp\WhatsAppLink;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    $action = $_POST['do'] ?? '';
    $reviewer = $_SESSION['admin_username'] ?? 'admin';
    $version = (int) ($_POST['review_version'] ?? 0);

    try {
        if ($action === 'approve') {
            $post = SocialPublishManager::approveAndPublish($id, $reviewer, $version);
            match ($post['publish_status']) {
                'published' => set_flash('success', "Approved and published to {$post['platform']} — post id {$post['external_post_id']}."),
                'simulated' => set_flash('success', "Approved — publish simulated (no active {$post['platform']} credential; the exact payload is in the activity log)."),
                default     => set_flash('danger', 'Approved, but the publish failed: ' . $post['publish_error'] . ' — fix the cause and hit Retry.'),
            };
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

$statusBadge = match ($post['status']) {
    'posted'   => '',
    'rejected' => 'danger',
    default    => 'orange',
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

            <div style="display:flex; gap:8px; margin-top:16px; flex-wrap:wrap">
                <?php if ($post['status'] === 'draft'): ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="review_version" value="<?= (int) $post['review_version'] ?>">
                        <input type="hidden" name="do" value="approve">
                        <button type="submit" class="belive-btn-primary">Approve &amp; publish</button>
                    </form>
                <?php endif; ?>
                <?php if ($post['status'] === 'approved' && in_array($post['publish_status'], ['failed', null], true)): ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="review_version" value="<?= (int) $post['review_version'] ?>">
                        <input type="hidden" name="do" value="retry">
                        <button type="submit" class="belive-btn-secondary"><?= $post['publish_status'] === 'failed' ? 'Retry publish' : 'Publish now' ?></button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($post['status'] === 'draft'): ?>
                <form method="post" style="margin-top:12px">
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
            <?php endif; ?>
        </div>
    </div>

    <div class="belive-col">
        <?php if ($isVideo && $scenes !== []): ?>
            <div class="belive-card" style="margin-bottom:16px">
                <div class="belive-card-title">🎬 Shot list</div>
                <p class="belive-muted" style="font-size:13px; margin-top:-4px">
                    What the AI wrote onto each shot. The closing WhatsApp card is added by the studio, not the model —
                    every reel ends with a way to reach Eve.
                </p>
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
