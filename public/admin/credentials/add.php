<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Auth;
use App\Models\ApiCredential;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();

$serviceOptions = [
    'whatsapp'   => 'Meta WhatsApp Cloud API (access token)',
    'anthropic'  => 'Claude — Anthropic API key',
    'gemini'     => 'Gemini — Google AI API key',
    'openai'     => 'OpenAI (ChatGPT) — API key',
    'openrouter' => 'OpenRouter — API key',
    'meta_graph' => 'Meta Graph — Page token (FB/IG publishing + comment capture)',
    'tiktok'     => 'TikTok — Content Posting API access token',
];

// Per-service extra fields stored in the credential's meta JSON.
$serviceMetaFields = [
    'whatsapp'   => ['phone_number_id', 'waba_id'],
    'meta_graph' => ['page_id', 'ig_user_id'],
    'tiktok'     => ['open_id'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();

    $service = $_POST['service'] ?? '';
    $label = trim($_POST['label'] ?? '');
    $key = trim($_POST['api_key'] ?? '');

    if (!isset($serviceOptions[$service]) || $key === '') {
        set_flash('danger', 'Pick a service and paste the API key.');
    } else {
        $meta = [];
        foreach ($serviceMetaFields[$service] ?? [] as $field) {
            $value = trim($_POST[$field] ?? '');
            if ($value !== '') {
                $meta[$field] = $value;
            }
        }

        // Saved dormant — "Test & activate" on the list view verifies it
        // against the real provider before it goes live.
        ApiCredential::store($service, $label ?: $serviceOptions[$service], $key, $meta, false);
        set_flash('success', 'Credential saved (encrypted). Now run “Test & activate” to verify it and put it live.');
        header('Location: /admin/credentials');
        exit;
    }
}

admin_header('Add credential', 'credentials');
?>
<div class="belive-page-head">
    <h1>Add API credential</h1>
    <a class="belive-btn-ghost" href="/admin/credentials">← Back</a>
</div>

<div class="belive-card" style="max-width:640px">
    <form method="post" action="/admin/credentials/add">
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">

        <div class="belive-field">
            <label for="credential-service">Service</label>
            <select id="credential-service" name="service" required>
                <?php foreach ($serviceOptions as $value => $label): ?>
                    <option value="<?= e($value) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="belive-field">
            <label for="label">Label <span class="belive-muted">(optional)</span></label>
            <input id="label" name="label" type="text" placeholder="e.g. Production key — July">
        </div>

        <div class="belive-field">
            <label for="api_key">API key / access token</label>
            <textarea id="api_key" name="api_key" rows="3" required autocomplete="off" spellcheck="false"></textarea>
            <div class="hint">Encrypted with AES-256-GCM before it touches the database. Shown masked afterwards.</div>
        </div>

        <div data-service-fields="whatsapp" style="display:none">
            <div class="belive-field">
                <label for="phone_number_id">Phone number ID</label>
                <input id="phone_number_id" name="phone_number_id" type="text" placeholder="From Meta App → WhatsApp → API Setup">
            </div>
            <div class="belive-field">
                <label for="waba_id">WhatsApp Business Account ID <span class="belive-muted">(optional)</span></label>
                <input id="waba_id" name="waba_id" type="text">
            </div>
        </div>

        <div data-service-fields="meta_graph" style="display:none">
            <div class="belive-field">
                <label for="page_id">Facebook Page ID <span class="belive-muted">(needed for FB publishing)</span></label>
                <input id="page_id" name="page_id" type="text" placeholder="From Meta Business Suite → Page settings">
            </div>
            <div class="belive-field">
                <label for="ig_user_id">Instagram Business account ID <span class="belive-muted">(needed for IG publishing)</span></label>
                <input id="ig_user_id" name="ig_user_id" type="text">
            </div>
            <div class="hint">
                For auto-publishing this must be a <strong>Page access token</strong> with
                <code>pages_manage_posts</code> and <code>instagram_content_publish</code>. Only one
                meta_graph credential is active at a time, so keep the comment-capture scopes on the
                same token if that feature is in use. Without an active credential, approvals publish
                in clearly-badged dry-run mode.
            </div>
        </div>

        <div data-service-fields="tiktok" style="display:none">
            <div class="belive-field">
                <label for="open_id">TikTok open_id <span class="belive-muted">(optional)</span></label>
                <input id="open_id" name="open_id" type="text">
            </div>
            <div class="hint">
                User access token with the <code>video.publish</code> scope from an approved TikTok
                developer app. Unaudited apps can only post SELF_ONLY; until then approvals publish
                in dry-run mode.
            </div>
        </div>

        <button type="submit" class="belive-btn-primary">Save encrypted</button>
    </form>
</div>
<?php admin_footer();
