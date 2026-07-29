<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Auth;
use App\Core\Database;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();

/**
 * The self-learning scoreboard: Mistake | Correction Applied | Rule Learned |
 * Times Reinforced | Status. Shows fact-correction rows AND sequencing-lesson
 * rows (the Setapak example) side by side, exactly as the proposal promised.
 */
$rules = Database::run(
    'SELECT m.*, f.error_type, f.feedback_source, f.comment AS feedback_comment
     FROM ai_learned_memory m
     LEFT JOIN ai_feedback f ON f.id = m.source_feedback_id
     ORDER BY m.id DESC
     LIMIT 200'
)->fetchAll();

$activeCount = count(array_filter($rules, fn ($r) => (int) $r['active'] === 1));

admin_header('Learning log', 'learning_log');
?>
<div class="belive-page-head">
    <h1>Learning log</h1>
    <div style="display:flex; gap:8px">
        <span class="belive-badge"><?= $activeCount ?> active rules</span>
        <span class="belive-badge muted"><?= count($rules) - $activeCount ?> retired (audit trail)</span>
    </div>
</div>

<div class="belive-card">
    <?php if ($rules === []): ?>
        <p class="belive-muted">Eve hasn't learned anything yet. Flag a wrong reply in
        <a href="/admin/chat_history">chat history</a>, or let the drop-off pattern detector find a
        sequencing lesson — every lesson lands here.</p>
    <?php else: ?>
        <div class="belive-table-wrap">
            <table class="belive-table">
                <thead>
                <tr>
                    <th>Mistake</th>
                    <th>Correction applied</th>
                    <th>Rule learned</th>
                    <th style="text-align:center">Times reinforced</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rules as $rule): ?>
                    <tr>
                        <td style="max-width:220px">
                            <?php if ($rule['error_type'] !== null): ?>
                                <span class="belive-badge <?= in_array($rule['error_type'], ['poor_sequencing', 'low_engagement'], true) ? '' : 'orange' ?>">
                                    <?= e(str_replace('_', ' ', $rule['error_type'])) ?>
                                </span>
                                <div class="belive-muted" style="font-size:12px; margin-top:4px">
                                    <?= e(mb_substr((string) $rule['feedback_comment'], 0, 140)) ?><?= mb_strlen((string) $rule['feedback_comment']) > 140 ? '…' : '' ?>
                                </div>
                            <?php else: ?>
                                <span class="belive-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:13px">
                            <?= e(str_replace('_', ' ', (string) ($rule['feedback_source'] ?? 'manual'))) ?>
                            <div class="belive-muted" style="font-size:12px"><?= e($rule['created_at']) ?></div>
                        </td>
                        <td style="max-width:300px">
                            <span class="belive-badge <?= $rule['rule_type'] === 'sequencing' ? '' : 'orange' ?>" style="font-size:11px"><?= e($rule['rule_type']) ?></span>
                            <span class="belive-badge muted" style="font-size:11px"><?= e($rule['context_tag']) ?></span>
                            <div style="margin-top:4px; font-size:13.5px">
                                <a href="/admin/learning_log/rule?id=<?= (int) $rule['id'] ?>"><?= e($rule['learned_rule']) ?></a>
                            </div>
                        </td>
                        <td style="text-align:center">
                            <strong><?= (int) $rule['times_reinforced'] ?></strong>
                            <?php if ((int) $rule['times_contradicted'] > 0): ?>
                                <div class="belive-muted" style="font-size:11px"><?= (int) $rule['times_contradicted'] ?>× contradicted</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int) $rule['active'] === 1): ?>
                                <span class="belive-badge">active · <?= number_format((float) $rule['confidence_score'], 2) ?></span>
                            <?php else: ?>
                                <span class="belive-badge danger">retired · <?= number_format((float) $rule['confidence_score'], 2) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="belive-card" style="margin-top:16px">
    <div class="belive-card-title">🧠 How Eve learns</div>
    <ul class="belive-check-list">
        <li><strong>Collect</strong> — admin flags, customer corrections, repeated questions, and drop-off patterns are auto-recorded</li>
        <li><strong>Learn</strong> — a real model call distills each piece of feedback into one reusable rule</li>
        <li><strong>Remember</strong> — rules are stored with a context tag and injected into every matching future conversation</li>
        <li><strong>Adapt</strong> — rules gain confidence when they work, lose it when contradicted, and retire below threshold</li>
    </ul>
</div>
<?php admin_footer();
