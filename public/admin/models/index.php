<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\AI\ModelRouter;
use App\Core\Auth;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();

// Registry drives the dropdowns — model choices are never hardcoded here.
$registry = ModelRouter::registry();

$phaseLabels = [
    'lead_gen'         => ['Lead generation', 'Scoring and classifying incoming leads across all channels'],
    'conversion'       => ['Conversion', 'Live WhatsApp conversations — understand, decide, reply'],
    'content_creation' => ['Content creation', 'Social captions and on-brand marketing copy'],
];

$active = [];
foreach (AI_PHASES as $phase) {
    $active[$phase] = ModelRouter::modelForPhase($phase);
}

admin_header('AI models', 'models');
?>
<div class="belive-page-head">
    <h1>AI models</h1>
    <span class="belive-badge">changes apply to the very next interaction</span>
</div>

<div class="belive-row">
    <?php foreach (AI_PHASES as $phase): ?>
        <div class="belive-col">
            <div class="belive-card">
                <div class="belive-card-title">🤖 <?= e($phaseLabels[$phase][0]) ?></div>
                <p class="belive-muted" style="font-size:13px; margin-bottom:14px"><?= e($phaseLabels[$phase][1]) ?></p>

                <form method="post" action="/admin/models/switch">
                    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                    <input type="hidden" name="phase" value="<?= e($phase) ?>">
                    <div class="belive-field">
                        <label>Active model</label>
                        <select name="model_key">
                            <?php foreach ($registry as $key => $meta): ?>
                                <option value="<?= e($key) ?>" <?= $key === $active[$phase] ? 'selected' : '' ?>>
                                    <?= e($meta['label']) ?> — <?= e($meta['cost_tier']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="hint"><?= e($registry[$active[$phase]]['purpose'] ?? '') ?></div>
                    </div>
                    <button type="submit" class="belive-btn-secondary">Set model</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="belive-card" style="margin-top:16px">
    <div class="belive-card-title">📚 Model registry</div>
    <div class="belive-table-wrap">
        <table class="belive-table">
            <thead><tr><th>Model</th><th>Provider</th><th>Purpose</th><th>Cost tier</th><th>Source</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($registry as $key => $meta): ?>
                <tr>
                    <td><code><?= e($key) ?></code></td>
                    <td><?= e($meta['provider']) ?></td>
                    <td><?= e($meta['purpose']) ?></td>
                    <td><span class="belive-badge <?= $meta['cost_tier'] === 'premium' ? 'orange' : '' ?>"><?= e($meta['cost_tier']) ?></span></td>
                    <td><span class="belive-badge <?= isset($meta['custom_id']) ? '' : 'muted' ?>"><?= isset($meta['custom_id']) ? 'custom' : 'built-in' ?></span></td>
                    <td style="text-align:right">
                        <?php if (isset($meta['custom_id'])): ?>
                            <form method="post" action="/admin/models/remove" style="display:inline"
                                  onsubmit="return confirm('Remove model \'<?= e($key) ?>\' from the registry?')">
                                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                                <input type="hidden" name="id" value="<?= (int) $meta['custom_id'] ?>">
                                <button type="submit" class="belive-btn-ghost" style="padding:6px 12px; font-size:13px; color:#c0392b">Remove</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="belive-card" style="margin-top:16px">
    <div class="belive-card-title">➕ Add a model</div>
    <p class="belive-muted" style="font-size:13px; margin-bottom:14px">
        Add any Anthropic or Google model by its exact API model id. The id is checked against the
        provider (using your active API key) before it can go live on a phase.</p>
    <form method="post" action="/admin/models/add">
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
        <div class="belive-row">
            <div class="belive-col">
                <div class="belive-field">
                    <label>Model id (exact API id)</label>
                    <input type="text" name="model_key" required placeholder="e.g. claude-haiku-4-5-20251001">
                </div>
                <div class="belive-field">
                    <label>Provider</label>
                    <select name="provider">
                        <option value="anthropic">Claude (Anthropic)</option>
                        <option value="gemini">Gemini (Google)</option>
                        <option value="openai">OpenAI (ChatGPT)</option>
                        <option value="openrouter">OpenRouter (any vendor)</option>
                    </select>
                </div>
            </div>
            <div class="belive-col">
                <div class="belive-field">
                    <label>Label (shown in dropdowns)</label>
                    <input type="text" name="label" placeholder="e.g. Claude Haiku 4.5">
                </div>
                <div class="belive-field">
                    <label>Cost tier</label>
                    <select name="cost_tier">
                        <option value="economy">economy</option>
                        <option value="standard" selected>standard</option>
                        <option value="premium">premium</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="belive-field">
            <label>Purpose (optional note)</label>
            <input type="text" name="purpose" placeholder="What is this model good at?">
        </div>
        <button type="submit" class="belive-btn-secondary">Add model</button>
    </form>
</div>
<?php admin_footer();
