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
    'meta_graph' => 'Meta Graph — FB/IG comment capture token',
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
        if ($service === 'whatsapp') {
            foreach (['phone_number_id', 'waba_id'] as $field) {
                $value = trim($_POST[$field] ?? '');
                if ($value !== '') {
                    $meta[$field] = $value;
                }
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

        <div id="whatsapp-extra-fields" style="display:none">
            <div class="belive-field">
                <label for="phone_number_id">Phone number ID</label>
                <input id="phone_number_id" name="phone_number_id" type="text" placeholder="From Meta App → WhatsApp → API Setup">
            </div>
            <div class="belive-field">
                <label for="waba_id">WhatsApp Business Account ID <span class="belive-muted">(optional)</span></label>
                <input id="waba_id" name="waba_id" type="text">
            </div>
        </div>

        <button type="submit" class="belive-btn-primary">Save encrypted</button>
    </form>
</div>
<?php admin_footer();
