<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Auth;
use App\Models\ApiCredential;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();

$credentials = ApiCredential::maskedList();

$serviceLabels = [
    'whatsapp'   => 'Meta WhatsApp Cloud API',
    'anthropic'  => 'Claude (Anthropic)',
    'gemini'     => 'Gemini (Google)',
    'meta_graph' => 'Meta Graph (FB/IG comments)',
];

admin_header('API credentials', 'credentials');
?>
<div class="belive-page-head">
    <h1>API credentials</h1>
    <a class="belive-btn-primary" href="/admin/credentials/add">+ Add credential</a>
</div>

<div class="belive-card">
    <?php if ($credentials === []): ?>
        <p class="belive-muted">No API keys yet. Add your Meta WhatsApp, Claude and Gemini keys to bring
        Eve online — keys are encrypted at rest (AES-256-GCM) and never shown in full again.</p>
    <?php else: ?>
        <table class="belive-table">
            <thead>
            <tr>
                <th>Service</th><th>Label</th><th>Key</th><th>Status</th><th>Last test</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($credentials as $cred): ?>
                <tr>
                    <td><?= e($serviceLabels[$cred['service']] ?? $cred['service']) ?></td>
                    <td><?= e($cred['label']) ?></td>
                    <td><code><?= e($cred['masked_key']) ?></code></td>
                    <td>
                        <?php if ((int) $cred['is_active'] === 1): ?>
                            <span class="belive-badge">active</span>
                        <?php else: ?>
                            <span class="belive-badge muted">inactive</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:13px">
                        <?php if ($cred['last_tested_at'] === null): ?>
                            <span class="belive-muted">never</span>
                        <?php elseif ($cred['test_status'] === 'ok'): ?>
                            <span class="belive-badge">✓ ok</span>
                            <span class="belive-muted"><?= e($cred['last_tested_at']) ?></span>
                        <?php else: ?>
                            <span class="belive-badge danger">✗ failed</span>
                            <span class="belive-muted" title="<?= e($cred['test_detail'] ?? '') ?>"><?= e($cred['last_tested_at']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right; white-space:nowrap">
                        <form method="post" action="/admin/credentials/test" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                            <input type="hidden" name="id" value="<?= (int) $cred['id'] ?>">
                            <button type="submit" class="belive-btn-ghost" style="padding:6px 12px; font-size:13px">Test connection</button>
                        </form>
                        <?php if ((int) $cred['is_active'] !== 1): ?>
                            <form method="post" action="/admin/credentials/test" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                                <input type="hidden" name="id" value="<?= (int) $cred['id'] ?>">
                                <input type="hidden" name="activate_on_success" value="1">
                                <button type="submit" class="belive-btn-secondary" style="padding:6px 12px; font-size:13px">Test &amp; activate</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="belive-card" style="margin-top:16px">
    <div class="belive-card-title">🔐 How keys are protected</div>
    <ul class="belive-check-list">
        <li>Encrypted at rest with AES-256-GCM — the key lives only in <code>.env</code></li>
        <li>Displayed masked (last 4 characters) — full keys are never shown again</li>
        <li>Decrypted only at the moment of an actual API call</li>
        <li>Test connection pings the real provider before a key goes live</li>
    </ul>
</div>
<?php admin_footer();
