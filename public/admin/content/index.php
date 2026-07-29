<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

/**
 * Content module — AI-generated social post drafts from live room metrics.
 * Fully automated pipeline: drafts arrive on demand (button below) or on
 * schedule (cron/auto_draft_content.php); admin approval publishes straight
 * to the platform via SocialPublishManager, with a dry-run fallback badged
 * 'simulated' when no platform credential is active.
 */

use App\AI\Skills\CreateSkill;
use App\Content\PromoVideoDrafter;
use App\Content\RoomVideoComposer;
use App\Core\Auth;
use App\Integrations\Social\SocialPublishManager;
use App\Integrations\WhatsApp\WhatsAppLink;
use App\Models\Room;
use App\Core\Database;
use App\Core\Settings;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();

// Automation controls: drafts per cron run, platforms covered, standing brief.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'settings') {
    Auth::requireCsrf();

    $max = max(1, min(20, (int) ($_POST['content_auto_max'] ?? 3)));
    $platforms = array_values(array_intersect((array) ($_POST['content_auto_platforms'] ?? []), CONTENT_PLATFORMS));
    $brief = trim((string) ($_POST['content_brief'] ?? ''));
    $mediaKind = in_array($_POST['content_auto_media'] ?? '', CONTENT_MEDIA_KINDS, true) ? $_POST['content_auto_media'] : 'image';

    if ($platforms === []) {
        set_flash('danger', 'Pick at least one platform for the daily drafts.');
    } elseif ($mediaKind === 'video' && !RoomVideoComposer::isAvailable()) {
        set_flash('danger', 'Daily reels need ffmpeg on this server — install it (or set FFMPEG_BIN in .env) before scheduling video drafts.');
    } else {
        $by = (string) ($_SESSION['admin_username'] ?? 'admin');
        Settings::set('content_auto_max', (string) $max, $by);
        Settings::set('content_auto_platforms', implode(',', $platforms), $by);
        Settings::set('content_auto_media', $mediaKind, $by);
        Settings::set('content_brief', mb_substr($brief, 0, 1000), $by);
        Settings::set('content_wa_prefill', mb_substr(trim((string) ($_POST['content_wa_prefill'] ?? '')), 0, 200), $by);
        set_flash('success', "Automation saved — up to $max " . ($mediaKind === 'video' ? 'reel' : 'caption')
            . "(s) per run across " . implode(', ', $platforms) . '.');
    }
    header('Location: /admin/content');
    exit;
}

// Generate a new draft on demand (real CreateSkill call on live room data).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'generate') {
    Auth::requireCsrf();

    $room = Room::find((int) ($_POST['room_id'] ?? 0));
    $platform = in_array($_POST['platform'] ?? '', CONTENT_PLATFORMS, true) ? $_POST['platform'] : 'facebook';
    $mediaKind = in_array($_POST['media_kind'] ?? '', CONTENT_MEDIA_KINDS, true) ? $_POST['media_kind'] : 'image';
    // Per-post steer wins; otherwise fall back to the standing brief.
    $brief = trim((string) ($_POST['brief'] ?? '')) ?: Settings::get('content_brief', '');

    if ($room === null) {
        set_flash('danger', 'Pick a room to feature.');
    } else {
        try {
            if ($mediaKind === 'video') {
                $video = PromoVideoDrafter::draft($room, $platform, $brief);
                set_flash('success', sprintf(
                    'Promo video rendered from this room\'s own photos — %d scenes, %.1fs, scripted by %s.',
                    $video['scenes'],
                    $video['seconds'],
                    $video['model']
                ));
            } else {
                $caption = CreateSkill::socialCaption($room, $platform, $brief);
                Database::run(
                    'INSERT INTO content_posts (platform, room_id, caption, status, generated_by_model, generated_via, image_url) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$platform, $room['id'], $caption['text'], 'draft', $caption['model'], 'manual', Room::photoUrls((int) $room['id'])[0] ?? null]
                );
                set_flash('success', 'Draft generated from live room metrics by ' . $caption['model'] . '.');
            }
        } catch (\Throwable $e) {
            set_flash('danger', 'Generation failed: ' . $e->getMessage());
        }
    }
    header('Location: /admin/content');
    exit;
}

$filter = $_GET['status'] ?? 'all';
$filterWhere = match ($filter) {
    'draft'    => "WHERE p.status = 'draft'",
    'failed'   => "WHERE p.status = 'approved'",
    'posted'   => "WHERE p.status = 'posted'",
    'rejected' => "WHERE p.status = 'rejected'",
    default    => '',
};
if ($filterWhere === '') {
    $filter = 'all';
}

$posts = Database::run(
    "SELECT p.*, r.name AS room_name, r.location AS area FROM content_posts p
     LEFT JOIN rooms r ON r.id = p.room_id $filterWhere ORDER BY p.id DESC LIMIT 100"
)->fetchAll();
$rooms = \App\Catalog\RoomRepository::filter(['tenure' => 'monthly'], 100);
$counts = SocialPublishManager::counts();

$autoMax = Settings::getInt('content_auto_max', 3);
$autoPlatforms = Settings::getList('content_auto_platforms', CONTENT_PLATFORMS);
$autoMedia = Settings::get('content_auto_media', 'image');
// Rendering is the one thing the studio cannot do on its own — say so up front
// rather than after an admin has waited on a failing Generate.
$videoReady = RoomVideoComposer::isAvailable();
$standingBrief = Settings::get('content_brief', '');
$waPrefill = Settings::get('content_wa_prefill', '');
// Exactly what a reader taps, built from a real room so the preview is honest.
$sampleWaLink = CreateSkill::captionWhatsappLink($rooms[0] ?? ['name' => 'a room', 'location' => 'KL'], 'facebook');
$waNumber = WhatsAppLink::number();

$platformIcons = ['facebook' => '📘', 'instagram' => '📷', 'tiktok' => '🎵'];
$filterTabs = [
    'all'      => "All ({$counts['all']})",
    'draft'    => "Awaiting approval ({$counts['pending']})",
    'failed'   => "Needs publish ({$counts['failed']})",
    'posted'   => "Posted ({$counts['posted']})",
    'rejected' => "Rejected ({$counts['rejected']})",
];

admin_header('Content', 'content');
?>
<div class="belive-page-head">
    <h1>Content studio</h1>
    <span class="belive-muted" style="font-size:13px">Room metrics in → on-brand captions out. Approve a draft and it publishes to the platform automatically (dry-run when no credential is live).</span>
</div>

<div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px">
    <?php foreach ($filterTabs as $key => $label): ?>
        <a class="<?= $filter === $key ? 'belive-btn-secondary' : 'belive-btn-ghost' ?>" style="padding:6px 14px; font-size:13px"
           href="/admin/content<?= $key === 'all' ? '' : '?status=' . e($key) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
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
                <option value="video"<?= $videoReady ? '' : ' disabled' ?>>🎬 Promo video (9:16 reel)</option>
            </select>
        </div>
        <div class="belive-field" style="flex:1 1 100%; margin-bottom:0">
            <label>What should this post be about? <span class="belive-muted" style="font-weight:400">(optional — blank uses the standing brief below)</span></label>
            <textarea name="brief" rows="2" maxlength="1000"
                      placeholder="e.g. push the zero-deposit angle for students moving in before September, mention the free weekly cleaning"></textarea>
        </div>
        <button type="submit" class="belive-btn-primary">Generate post</button>
        <div class="belive-muted" style="flex:1 1 100%; font-size:12.5px">
            <?php if ($videoReady): ?>
                🎬 A promo video is cut from the room's <em>own</em> photos and tour clips — the AI writes the scenes and the
                caption, never the footage. Rendering takes a few seconds per scene, so give Generate a moment.
            <?php else: ?>
                🎬 Promo video is unavailable — <code>ffmpeg</code> was not found on this server. Install it, or point
                <code>FFMPEG_BIN</code> in <code>.env</code> at the executable, and the option turns on.
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="belive-card" style="margin-bottom:16px">
    <div class="belive-card-title">⚙️ Daily automation</div>
    <p class="belive-muted" style="font-size:13px; margin-top:-4px">
        <code>cron/auto_draft_content.php</code> runs once a day and drafts up to the limit below — currently
        <strong><?= (int) $autoMax ?> per run ≈ <?= (int) $autoMax * 7 ?> a week</strong>. Drafts still wait for your approval before publishing.
    </p>
    <form method="post" action="/admin/content" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-start">
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
        <input type="hidden" name="do" value="settings">
        <div class="belive-field" style="flex:0 0 150px; margin-bottom:0">
            <label>Drafts per run</label>
            <input type="number" name="content_auto_max" min="1" max="20" value="<?= (int) $autoMax ?>">
        </div>
        <div class="belive-field" style="flex:0 0 180px; margin-bottom:0">
            <label>Draft type</label>
            <select name="content_auto_media">
                <option value="image"<?= $autoMedia === 'image' ? ' selected' : '' ?>>🖼 Photo posts</option>
                <option value="video"<?= $autoMedia === 'video' ? ' selected' : '' ?><?= $videoReady ? '' : ' disabled' ?>>🎬 Promo videos</option>
            </select>
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
        <p class="belive-muted"><?= $filter === 'all' ? 'No drafts yet — generate one above, or let the daily cron draft for you.' : 'Nothing in this state right now.' ?></p>
    <?php else: ?>
        <table class="belive-table">
            <thead><tr><th>Platform</th><th>Type</th><th>Room</th><th>Caption</th><th>Model</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($posts as $post): ?>
                <tr>
                    <td style="white-space:nowrap"><?= $platformIcons[$post['platform']] ?> <?= e(ucfirst($post['platform'])) ?></td>
                    <td style="white-space:nowrap; font-size:13px"><?= $post['media_kind'] === 'video' ? '🎬 Video' : '🖼 Photo' ?></td>
                    <td style="font-size:13px"><?= e($post['room_name'] ? "{$post['room_name']} ({$post['area']})" : '—') ?></td>
                    <td style="font-size:13px; max-width:320px"><?= e(mb_substr($post['caption'], 0, 120)) ?><?= mb_strlen($post['caption']) > 120 ? '…' : '' ?></td>
                    <td style="font-size:12.5px"><code><?= e($post['generated_by_model']) ?></code></td>
                    <td>
                        <span class="belive-badge <?= match ($post['status']) { 'posted' => '', 'rejected' => 'danger', default => 'orange' } ?>"><?= e($post['status']) ?></span>
                        <?php if ($post['publish_status']): ?>
                            <span class="belive-badge <?= match ($post['publish_status']) { 'published' => '', 'simulated' => 'orange', default => 'danger' } ?>" style="font-size:11px"><?= e($post['publish_status']) ?></span>
                        <?php endif; ?>
                        <?php if ($post['publish_status'] === 'failed' && $post['publish_error']): ?>
                            <div class="belive-muted" style="font-size:11px" title="<?= e($post['publish_error']) ?>"><?= e(mb_substr($post['publish_error'], 0, 60)) ?><?= mb_strlen($post['publish_error']) > 60 ? '…' : '' ?></div>
                        <?php endif; ?>
                        <?php if ($post['posted_at']): ?><div class="belive-muted" style="font-size:11px"><?= e($post['posted_at']) ?></div><?php endif; ?>
                    </td>
                    <td><a class="belive-btn-ghost" style="padding:5px 12px; font-size:13px" href="/admin/content/preview?id=<?= (int) $post['id'] ?>">Preview</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php admin_footer();
