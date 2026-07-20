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
use App\Core\Auth;
use App\Integrations\Social\SocialPublishManager;
use App\Models\Room;
use App\Core\Database;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();

// Generate a new draft on demand (real CreateSkill call on live room data).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'generate') {
    Auth::requireCsrf();

    $room = Room::find((int) ($_POST['room_id'] ?? 0));
    $platform = in_array($_POST['platform'] ?? '', CONTENT_PLATFORMS, true) ? $_POST['platform'] : 'facebook';

    if ($room === null) {
        set_flash('danger', 'Pick a room to feature.');
    } else {
        try {
            $caption = CreateSkill::socialCaption($room, $platform);
            Database::run(
                'INSERT INTO content_posts (platform, room_id, caption, status, generated_by_model, generated_via, image_url) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$platform, $room['id'], $caption['text'], 'draft', $caption['model'], 'manual', Room::photoUrls((int) $room['id'])[0] ?? null]
            );
            set_flash('success', 'Draft generated from live room metrics by ' . $caption['model'] . '.');
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
        <button type="submit" class="belive-btn-primary">Generate caption</button>
    </form>
</div>

<div class="belive-card">
    <?php if ($posts === []): ?>
        <p class="belive-muted"><?= $filter === 'all' ? 'No drafts yet — generate one above, or let the daily cron draft for you.' : 'Nothing in this state right now.' ?></p>
    <?php else: ?>
        <table class="belive-table">
            <thead><tr><th>Platform</th><th>Room</th><th>Caption</th><th>Model</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($posts as $post): ?>
                <tr>
                    <td style="white-space:nowrap"><?= $platformIcons[$post['platform']] ?> <?= e(ucfirst($post['platform'])) ?></td>
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
