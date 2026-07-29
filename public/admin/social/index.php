<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

/**
 * Social auto-reply — comment on a post, get a WhatsApp link.
 *
 * Everything here is copy and policy: the transport lives in MetaMessenger,
 * the decision in CommentResponder. The table below is the receipt — which
 * comment, which reply, and whether that person actually landed on WhatsApp.
 */

use App\Core\Auth;
use App\Core\Settings;
use App\Integrations\Meta\InstagramApi;
use App\Integrations\Meta\MetaMessenger;
use App\Models\SocialReply;
use App\Pipeline\LeadGeneration\CommentResponder;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();

    $by = (string) ($_SESSION['admin_username'] ?? 'admin');
    $number = preg_replace('/\D/', '', (string) ($_POST['social_wa_number'] ?? ''));
    $scope = ($_POST['social_reply_scope'] ?? 'enquiry') === 'all' ? 'all' : 'enquiry';

    Settings::set('social_autoreply_enabled', isset($_POST['social_autoreply_enabled']) ? '1' : '0', $by);
    Settings::set('social_reply_scope', $scope, $by);
    Settings::set('social_wa_number', $number, $by);
    Settings::set('social_wa_prefill', mb_substr(trim((string) ($_POST['social_wa_prefill'] ?? '')), 0, 200), $by);
    Settings::set('social_comment_public_reply', mb_substr(trim((string) ($_POST['social_comment_public_reply'] ?? '')), 0, 600), $by);
    Settings::set('social_comment_dm', mb_substr(trim((string) ($_POST['social_comment_dm'] ?? '')), 0, 900), $by);
    Settings::set('social_dm_reply', mb_substr(trim((string) ($_POST['social_dm_reply'] ?? '')), 0, 900), $by);

    set_flash(
        $number === '' ? 'danger' : 'success',
        $number === ''
            ? 'Saved — but with no WhatsApp number the link falls back to the shared wa.link and attribution is lost.'
            : 'Auto-reply settings saved.'
    );
    header('Location: /admin/social');
    exit;
}

$enabled = Settings::get('social_autoreply_enabled', '1') === '1';
$scope = Settings::get('social_reply_scope', 'enquiry');
$number = Settings::get('social_wa_number', $_ENV['EVE_WA_NUMBER'] ?? '');
$prefill = Settings::get('social_wa_prefill', '');
$publicReply = Settings::get('social_comment_public_reply', '');
$commentDm = Settings::get('social_comment_dm', '');
$dmReply = Settings::get('social_dm_reply', '');

$messenger = new MetaMessenger();
$liveOn = array_filter([
    'Facebook'  => $messenger->isConfigured('facebook'),
    'Instagram' => $messenger->isConfigured('instagram'),
]);
$igPath = InstagramApi::usesInstagramLogin()
    ? 'Instagram Login — graph.instagram.com, using the `instagram` credential'
    : 'Facebook Login — graph.facebook.com, using the Page token on `meta_graph`';

$counts = SocialReply::counts();
$events = SocialReply::recent(60);
$sampleLink = CommentResponder::whatsappLink('BL7A3F2C', 'instagram');

$statusBadge = static fn (string $status): string => match ($status) {
    'sent'      => '',
    'simulated' => 'orange',
    'failed'    => 'danger',
    default     => 'orange',
};

admin_header('Social auto-reply', 'social');
?>
<div class="belive-page-head">
    <h1>Social auto-reply</h1>
    <span class="belive-muted" style="font-size:13px">A comment on Facebook or Instagram gets a public reply and a private DM holding a WhatsApp link — where Eve can actually quote, schedule and book.</span>
</div>

<div class="belive-card" style="margin-bottom:16px">
    <div class="belive-card-title">📡 Live status</div>
    <p style="font-size:13.5px; margin-top:-4px">
        <?php if ($liveOn === []): ?>
            <span class="belive-badge orange">simulated</span>
            No active <code>meta_graph</code> credential with a <code>page_id</code> / <code>ig_user_id</code> — replies are
            composed and logged to the activity log, <strong>not delivered</strong>. Add one under
            <a href="/admin/credentials">API credentials</a> to go live.
        <?php else: ?>
            <span class="belive-badge">live</span>
            Replying on <strong><?= e(implode(' and ', array_keys($liveOn))) ?></strong>.
        <?php endif; ?>
    </p>
    <p class="belive-muted" style="font-size:12.5px">
        Instagram path in use: <strong><?= e($igPath) ?></strong>. Meta ships two Instagram APIs and
        they take different tokens — this is decided by whether an <code>instagram</code> credential is active.
    </p>
    <p class="belive-muted" style="font-size:12.5px">
        Callback URL for the Messenger and Instagram products:
        <code><?= e(rtrim($_ENV['APP_URL'] ?? 'https://your-domain', '/')) ?>/webhook/meta</code> —
        subscribe the fields <code>feed</code>, <code>comments</code> and <code>messages</code>, then call
        <code>POST /{page-id}/subscribed_apps</code>. Without that last call Meta accepts the config and sends nothing.
    </p>
    <div style="display:flex; gap:26px; flex-wrap:wrap; margin-top:10px">
        <div><div style="font-size:24px; font-weight:600"><?= (int) $counts['answered'] ?></div><span class="belive-muted" style="font-size:12px">events answered</span></div>
        <div><div style="font-size:24px; font-weight:600"><?= (int) $counts['delivered'] ?></div><span class="belive-muted" style="font-size:12px">DMs really delivered</span></div>
        <div><div style="font-size:24px; font-weight:600"><?= (int) $counts['converted'] ?></div><span class="belive-muted" style="font-size:12px">landed on WhatsApp</span></div>
    </div>
</div>

<div class="belive-card" style="margin-bottom:16px">
    <div class="belive-card-title">⚙️ Reply settings</div>
    <form method="post" action="/admin/social">
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">

        <div style="display:flex; gap:20px; flex-wrap:wrap; align-items:flex-start">
            <div class="belive-field" style="flex:0 0 210px">
                <label>Auto-reply</label>
                <label style="font-weight:400; display:flex; gap:8px; align-items:center; padding-top:6px">
                    <input type="checkbox" name="social_autoreply_enabled" <?= $enabled ? 'checked' : '' ?>>
                    Answer comments and DMs
                </label>
            </div>
            <div class="belive-field" style="flex:1; min-width:240px">
                <label>Who gets a DM</label>
                <select name="social_reply_scope">
                    <option value="enquiry" <?= $scope === 'enquiry' ? 'selected' : '' ?>>Only comments that read like an enquiry (recommended)</option>
                    <option value="all" <?= $scope === 'all' ? 'selected' : '' ?>>Every comment</option>
                </select>
                <span class="belive-muted" style="font-size:12px">"Nice pic 😍" does not need a sales DM — and Meta's spam rules agree.</span>
            </div>
            <div class="belive-field" style="flex:1; min-width:200px">
                <label>WhatsApp number (digits only, with country code)</label>
                <input type="text" name="social_wa_number" value="<?= e($number) ?>" placeholder="60123456789" maxlength="20">
            </div>
        </div>

        <div class="belive-field">
            <label>Prefilled first message <span class="belive-muted" style="font-weight:400">— must keep <code>{token}</code>, that is what proves the lead came from the post</span></label>
            <input type="text" name="social_wa_prefill" value="<?= e($prefill) ?>" maxlength="200">
            <span class="belive-muted" style="font-size:12px">Preview: <code style="word-break:break-all"><?= e($sampleLink) ?></code></span>
        </div>

        <div class="belive-field">
            <label>Public reply under the comment <span class="belive-muted" style="font-weight:400">— leave blank to reply privately only</span></label>
            <input type="text" name="social_comment_public_reply" value="<?= e($publicReply) ?>" maxlength="600">
        </div>

        <div class="belive-field">
            <label>Private message sent after a comment</label>
            <textarea name="social_comment_dm" rows="5" maxlength="900"><?= e($commentDm) ?></textarea>
        </div>

        <div class="belive-field">
            <label>Reply when someone messages the page directly</label>
            <textarea name="social_dm_reply" rows="5" maxlength="900"><?= e($dmReply) ?></textarea>
        </div>

        <p class="belive-muted" style="font-size:12.5px">
            Placeholders: <code>{name}</code> first name (or "there"), <code>{link}</code> the WhatsApp link, <code>{platform}</code> Facebook/Instagram.
        </p>
        <button type="submit" class="belive-btn-primary">Save settings</button>
    </form>
</div>

<div class="belive-card">
    <div class="belive-card-title">📜 Answered events</div>
    <?php if ($events === []): ?>
        <p class="belive-muted">Nothing yet. Comment on a connected post — or run <code>php tests/run.php</code> to see the flow end to end.</p>
    <?php else: ?>
        <div class="belive-table-wrap">
            <table class="belive-table">
                <thead><tr><th>When</th><th>Where</th><th>Who</th><th>Public</th><th>DM</th><th>Token</th><th>Landed on WhatsApp</th></tr></thead>
                <tbody>
                <?php foreach ($events as $event): ?>
                    <tr>
                        <td style="font-size:12.5px; white-space:nowrap"><?= e($event['created_at']) ?></td>
                        <td style="font-size:13px; white-space:nowrap">
                            <?= $event['platform'] === 'instagram' ? '📷' : '📘' ?>
                            <?= e($event['event_type'] === 'comment' ? 'Comment' : 'DM') ?>
                        </td>
                        <td style="font-size:13px"><?= e($event['sender_name'] ?? $event['sender_id']) ?></td>
                        <td><span class="belive-badge <?= $statusBadge($event['public_reply']) ?>" style="font-size:11px"><?= e($event['public_reply']) ?></span></td>
                        <td>
                            <span class="belive-badge <?= $statusBadge($event['private_reply']) ?>" style="font-size:11px"><?= e($event['private_reply']) ?></span>
                            <?php if ($event['error']): ?>
                                <div class="belive-muted" style="font-size:11px" title="<?= e($event['error']) ?>"><?= e(mb_substr($event['error'], 0, 60)) ?><?= mb_strlen($event['error']) > 60 ? '…' : '' ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px"><code><?= e($event['ref_token'] ?? '—') ?></code></td>
                        <td style="font-size:12.5px">
                            <?php if ($event['claimed_at']): ?>
                                <span class="belive-badge">✓ <?= e($event['claimed_name'] ?: $event['claimed_phone']) ?></span>
                                <div class="belive-muted" style="font-size:11px"><?= e($event['claimed_at']) ?></div>
                            <?php else: ?>
                                <span class="belive-muted">not yet</span>
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
