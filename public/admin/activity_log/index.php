<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Auth;
use App\Core\Database;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();

/**
 * The audit trail: every AI action with which model handled it — the panel
 * that answers "which model made this decision" (non-negotiable build rule).
 */
$rows = Database::run(
    'SELECT a.*, l.name AS lead_name FROM ai_activity_log a
     LEFT JOIN leads l ON l.id = a.lead_id
     ORDER BY a.id DESC LIMIT 300'
)->fetchAll();

admin_header('Activity log', 'activity_log');
?>
<div class="belive-page-head">
    <h1>Activity log</h1>
    <span class="belive-muted" style="font-size:13px">Every AI action, with the model that handled it.</span>
</div>

<div class="belive-card">
    <?php if ($rows === []): ?>
        <p class="belive-muted">No activity yet.</p>
    <?php else: ?>
        <table class="belive-table">
            <thead><tr><th>When</th><th>Action</th><th>Phase</th><th>Model</th><th>Lead</th><th>Detail</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php
                // Shorten long details for the table, but say so with an ellipsis
                // and keep the full text on hover — a silent cut reads like a bug.
                $detail = (string) $row['detail'];
                $short = mb_substr($detail, 0, 160);
                if ($short !== $detail) {
                    $break = mb_strrpos($short, ' ');
                    $short = rtrim($break === false ? $short : mb_substr($short, 0, $break), " ,.;:") . '…';
                }
                ?>
                <tr>
                    <td style="white-space:nowrap; font-size:13px"><?= e($row['created_at']) ?></td>
                    <td><span class="belive-badge <?= str_contains($row['action'], 'learn') || str_contains($row['action'], 'rule') ? '' : 'muted' ?>"><?= e(str_replace('_', ' ', $row['action'])) ?></span></td>
                    <td style="font-size:13px"><?= e($row['phase'] ? str_replace('_', ' ', $row['phase']) : '—') ?></td>
                    <td style="font-size:13px"><code><?= e($row['model_used'] ?? '—') ?></code></td>
                    <td style="font-size:13px">
                        <?php if ($row['lead_id'] !== null): ?>
                            <a href="/admin/leads/view?id=<?= (int) $row['lead_id'] ?>"><?= e($row['lead_name'] ?: '#' . $row['lead_id']) ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td style="font-size:12.5px; max-width:340px" class="belive-muted" title="<?= e($detail) ?>"><?= e($short) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php admin_footer();
