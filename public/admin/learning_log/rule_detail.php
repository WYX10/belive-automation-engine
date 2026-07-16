<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Auth;
use App\Core\Database;
use App\Models\Interaction;
use App\Models\LearnedMemory;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();

$rule = LearnedMemory::find((int) ($_GET['id'] ?? 0));
if ($rule === null) {
    set_flash('danger', 'Rule not found.');
    header('Location: /admin/learning_log');
    exit;
}

$feedback = $rule['source_feedback_id'] !== null
    ? Database::run('SELECT * FROM ai_feedback WHERE id = ?', [$rule['source_feedback_id']])->fetch() ?: null
    : null;

$flaggedInteraction = $feedback !== null && $feedback['interaction_id'] !== null
    ? Interaction::find((int) $feedback['interaction_id'])
    : null;

// Replies this rule has shaped (memory_used carries the rule id).
$usages = Database::run(
    "SELECT id, lead_id, message_out, created_at FROM ai_interactions
     WHERE direction = 'outbound' AND JSON_CONTAINS(COALESCE(memory_used, '[]'), ?)
     ORDER BY id DESC LIMIT 20",
    [(string) (int) $rule['id']]
)->fetchAll();

admin_header('Rule detail', 'learning_log');
?>
<div class="belive-page-head">
    <h1>Rule #<?= (int) $rule['id'] ?></h1>
    <a class="belive-btn-ghost" href="/admin/learning_log">← Back to learning log</a>
</div>

<div class="belive-card">
    <div style="display:flex; gap:8px; margin-bottom:12px; flex-wrap:wrap">
        <span class="belive-badge <?= $rule['rule_type'] === 'sequencing' ? '' : 'orange' ?>"><?= e($rule['rule_type']) ?></span>
        <span class="belive-badge muted">context: <?= e($rule['context_tag']) ?></span>
        <?php if ((int) $rule['active'] === 1): ?>
            <span class="belive-badge">active · confidence <?= number_format((float) $rule['confidence_score'], 2) ?></span>
        <?php else: ?>
            <span class="belive-badge danger">retired · confidence <?= number_format((float) $rule['confidence_score'], 2) ?></span>
        <?php endif; ?>
    </div>

    <h2 style="font-size:17px; line-height:1.5">“<?= e($rule['learned_rule']) ?>”</h2>

    <div class="belive-row" style="margin-top:16px">
        <div class="belive-col">
            <div class="belive-stat">
                <div class="belive-stat-icon teal">💪</div>
                <div>
                    <div class="belive-stat-number teal"><?= (int) $rule['times_reinforced'] ?></div>
                    <div class="belive-stat-label">times reinforced</div>
                </div>
            </div>
        </div>
        <div class="belive-col">
            <div class="belive-stat">
                <div class="belive-stat-icon">⚡</div>
                <div>
                    <div class="belive-stat-number"><?= (int) $rule['times_contradicted'] ?></div>
                    <div class="belive-stat-label">times contradicted</div>
                </div>
            </div>
        </div>
        <div class="belive-col">
            <div class="belive-stat">
                <div class="belive-stat-icon teal">🕐</div>
                <div>
                    <div class="belive-stat-number teal" style="font-size:15px"><?= e($rule['last_used_at'] ?? 'never') ?></div>
                    <div class="belive-stat-label">last used</div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($feedback !== null): ?>
<div class="belive-card" style="margin-top:16px">
    <div class="belive-card-title">📥 Source feedback</div>
    <p style="font-size:14px">
        <span class="belive-badge orange"><?= e(str_replace('_', ' ', $feedback['error_type'])) ?></span>
        <span class="belive-badge muted"><?= e(str_replace('_', ' ', $feedback['feedback_source'])) ?></span>
        <span class="belive-muted" style="font-size:12px"><?= e($feedback['created_at']) ?></span>
    </p>
    <p style="margin-top:8px; font-size:14px"><?= nl2br(e($feedback['comment'])) ?></p>
    <?php if ($flaggedInteraction !== null): ?>
        <div class="reasoning-box" style="margin-top:10px">
            <strong>The flagged Eve reply:</strong> <?= e($flaggedInteraction['message_out'] ?? '(n/a)') ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="belive-card" style="margin-top:16px">
    <div class="belive-card-title">📤 Replies this rule has shaped (<?= count($usages) ?>)</div>
    <?php if ($usages === []): ?>
        <p class="belive-muted">Not used yet — it will apply to the next matching conversation.</p>
    <?php else: ?>
        <table class="belive-table">
            <thead><tr><th>When</th><th>Lead</th><th>Reply</th></tr></thead>
            <tbody>
            <?php foreach ($usages as $use): ?>
                <tr>
                    <td style="white-space:nowrap; font-size:13px"><?= e($use['created_at']) ?></td>
                    <td><a href="/admin/leads/view?id=<?= (int) $use['lead_id'] ?>">#<?= (int) $use['lead_id'] ?></a></td>
                    <td style="font-size:13px"><?= e(mb_substr((string) $use['message_out'], 0, 160)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php admin_footer();
