<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\AI\ModelRouter;
use App\Core\Auth;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();

// Registry drives the dropdowns — model choices are never hardcoded here.
$registry = require APP_ROOT . '/config/ai_models.php';

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
    <table class="belive-table">
        <thead><tr><th>Model</th><th>Provider</th><th>Purpose</th><th>Cost tier</th></tr></thead>
        <tbody>
        <?php foreach ($registry as $key => $meta): ?>
            <tr>
                <td><code><?= e($key) ?></code></td>
                <td><?= e($meta['provider']) ?></td>
                <td><?= e($meta['purpose']) ?></td>
                <td><span class="belive-badge <?= $meta['cost_tier'] === 'premium' ? 'orange' : '' ?>"><?= e($meta['cost_tier']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php admin_footer();
