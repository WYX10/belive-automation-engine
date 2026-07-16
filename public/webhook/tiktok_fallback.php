<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

/**
 * Manual-intake fallback for channels whose platforms expose no third-party
 * inbound API: TikTok DMs/comments and PropertyGuru/iProperty enquiries.
 * Honest by design — admin logs the enquiry, and everything downstream
 * (scoring, conversion, memory, booking) is identical to automated channels.
 * Admin-authenticated despite living under /webhook (spec-fixed location).
 */

use App\Core\Auth;
use App\Integrations\TikTok\FallbackNotifier;
use App\Pipeline\LeadGeneration\ListingPortalMonitor;

require dirname(__DIR__) . '/admin/_layout.php';
Auth::requireAdmin();

$channels = [
    'tiktok'       => 'TikTok (DM / comment)',
    'ibilik'       => 'ibilik.my enquiry (BeLive\'s primary portal)',
    'roomz'        => 'roomz.asia enquiry',
    'propertyguru' => 'PropertyGuru enquiry',
    'iproperty'    => 'iProperty enquiry',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();

    $channel = $_POST['channel'] ?? '';
    $contact = trim($_POST['contact'] ?? '');
    $name = trim($_POST['name'] ?? '') ?: null;
    $text = trim($_POST['enquiry'] ?? '');

    if (!isset($channels[$channel]) || $contact === '' || $text === '') {
        set_flash('danger', 'Channel, contact and the enquiry text are required.');
    } else {
        $lead = $channel === 'tiktok'
            ? FallbackNotifier::logEnquiry($contact, $name, $text)
            : ListingPortalMonitor::logEnquiry($channel, $contact, $name, $text);

        set_flash('success', 'Enquiry logged as lead #' . $lead['id'] . ' — scored and flowing through the same pipeline as every other channel.');
        header('Location: /admin/leads/view?id=' . $lead['id']);
        exit;
    }
}

admin_header('Manual lead intake', 'leads');
?>
<div class="belive-page-head">
    <h1>Manual lead intake</h1>
    <a class="belive-btn-ghost" href="/admin/leads">← Leads</a>
</div>

<div class="belive-alert warning" style="max-width:680px">
    <strong>Why manual?</strong> TikTok's official API doesn't allow third-party DM/comment capture, and
    PropertyGuru / iProperty expose no inbound-enquiry API. Rather than fake automation, enquiries from
    these channels are logged here — <em>everything downstream (AI scoring, conversion, memory, booking)
    runs identically to the automated channels.</em>
</div>

<div class="belive-card" style="max-width:680px">
    <form method="post" action="/webhook/tiktok_fallback">
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">

        <div class="belive-field">
            <label for="channel">Channel</label>
            <select id="channel" name="channel" required>
                <?php foreach ($channels as $value => $label): ?>
                    <option value="<?= e($value) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="belive-field">
            <label for="contact">Contact — WhatsApp number if shared, else the platform handle</label>
            <input id="contact" name="contact" type="text" required placeholder="60123456789 or @tiktok_handle">
            <div class="hint">With a number, Eve follows up on WhatsApp automatically. A handle is tracked until they message us.</div>
        </div>

        <div class="belive-field">
            <label for="name">Name <span class="belive-muted">(optional)</span></label>
            <input id="name" name="name" type="text">
        </div>

        <div class="belive-field">
            <label for="enquiry">The enquiry, as they wrote it</label>
            <textarea id="enquiry" name="enquiry" rows="3" required placeholder="e.g. Hi is the Setapak room still available? What's the rent?"></textarea>
        </div>

        <button type="submit" class="belive-btn-primary">Log lead &amp; run AI scoring</button>
    </form>
</div>
<?php admin_footer();
