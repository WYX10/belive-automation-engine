<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

/**
 * Content module — AI-generated social post drafts from live room metrics.
 * Publishing is explicitly semi-automated: platform publish APIs (FB/IG page
 * publishing needs app review; TikTok has none) are out of competition
 * budget/scope, so admin approves and posts manually, then marks it posted.
 */

use App\AI\Skills\CreateSkill;
use App\Core\Auth;
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
                'INSERT INTO content_posts (platform, room_id, caption, status, generated_by_model) VALUES (?, ?, ?, ?, ?)',
                [$platform, $room['id'], $caption['text'], 'draft', $caption['model']]
            );
            set_flash('success', 'Draft generated from live room metrics by ' . $caption['model'] . '.');
        } catch (\Throwable $e) {
            set_flash('danger', 'Generation failed: ' . $e->getMessage());
        }
    }
    header('Location: /admin/content');
    exit;
}

$posts = Database::run(
    'SELECT p.*, r.name AS room_name, r.location AS area FROM content_posts p
     LEFT JOIN rooms r ON r.id = p.room_id ORDER BY p.id DESC LIMIT 100'
)->fetchAll();
$rooms = \App\Catalog\RoomRepository::filter(['tenure' => 'monthly'], 100);

$platformIcons = ['facebook' => '📘', 'instagram' => '📷', 'tiktok' => '🎵'];

admin_header('Content', 'content');
?>
<div class="belive-page-head">
    <h1>Content studio</h1>
    <span class="belive-muted" style="font-size:13px">Room metrics in → on-brand captions out. Publish is admin-approved (semi-automated by design).</span>
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
                    <option value="<?= (int) $room['id'] ?>"><?= e($room['name']) ?> — <?= e($room['location']) ?>, RM<?= e(number_format($room['price_at_tenure'])) ?>/mo flexible</option>
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
        <p class="belive-muted">No drafts yet — generate one above.</p>
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
                        <span class="belive-badge <?= $post['status'] === 'posted' ? '' : 'orange' ?>"><?= e($post['status']) ?></span>
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
