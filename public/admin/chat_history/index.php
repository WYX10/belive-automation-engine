<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Auth;
use App\Core\Database;
use App\Models\Interaction;
use App\Models\Lead;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();

// Leads that actually have conversations, latest activity first.
$leads = Database::run(
    'SELECT l.*, MAX(i.created_at) AS last_message_at, COUNT(i.id) AS message_count
     FROM leads l
     JOIN ai_interactions i ON i.lead_id = l.id AND i.direction IN ("inbound","outbound")
     GROUP BY l.id
     ORDER BY last_message_at DESC
     LIMIT 50'
)->fetchAll();

$selectedId = (int) ($_GET['lead_id'] ?? ($leads[0]['id'] ?? 0));
$selected = $selectedId > 0 ? Lead::find($selectedId) : null;
$transcript = $selected !== null ? Interaction::transcript($selectedId, 100) : [];

admin_header('Chat history', 'chat_history');
?>
<div class="belive-page-head">
    <h1>Chat history</h1>
    <span class="belive-muted" style="font-size:13px">Flag any wrong reply — Eve learns from it immediately.</span>
</div>

<div class="belive-row">
    <div style="flex:0 0 300px; min-width:260px">
        <div class="belive-card" style="padding:12px">
            <?php if ($leads === []): ?>
                <p class="belive-muted" style="padding:8px">No conversations yet.</p>
            <?php endif; ?>
            <?php foreach ($leads as $lead): ?>
                <a href="/admin/chat_history?lead_id=<?= (int) $lead['id'] ?>"
                   style="display:block; padding:10px 12px; border-radius:10px; text-decoration:none; color:inherit;
                          <?= (int) $lead['id'] === $selectedId ? 'background:var(--belive-orange-soft);' : '' ?>">
                    <div style="display:flex; justify-content:space-between; gap:8px">
                        <strong style="font-size:14px"><?= e($lead['name'] ?: $lead['wa_phone']) ?></strong>
                        <span class="belive-badge <?= $lead['status'] === 'new' ? 'orange' : '' ?>" style="font-size:11px"><?= e($lead['status']) ?></span>
                    </div>
                    <div class="belive-muted" style="font-size:12px; margin-top:2px">
                        <?= (int) $lead['message_count'] ?> messages · <?= e($lead['last_message_at']) ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="belive-col">
        <div class="belive-card">
            <?php if ($selected === null): ?>
                <p class="belive-muted">Select a conversation.</p>
            <?php else: ?>
                <div class="belive-card-title">
                    💬 <?= e($selected['name'] ?: $selected['wa_phone']) ?>
                    <span class="belive-muted" style="font-weight:400; font-size:13px">· <?= e($selected['wa_phone']) ?> · via <?= e($selected['source_channel']) ?></span>
                </div>

                <div class="chat-thread">
                    <?php foreach ($transcript as $msg): ?>
                        <?php $inbound = $msg['direction'] === 'inbound'; ?>
                        <div class="chat-bubble <?= $inbound ? 'inbound' : 'outbound' ?> <?= (int) $msg['flagged'] === 1 ? 'flagged' : '' ?>">
                            <?= nl2br(e($inbound ? $msg['message_in'] : $msg['message_out'])) ?>
                            <div class="meta">
                                <span><?= e($msg['created_at']) ?></span>
                                <?php if (!$inbound): ?>
                                    <span><?= e($msg['model_used']) ?></span>
                                    <?php if ($msg['message_kind']): ?><span><?= e($msg['message_kind']) ?></span><?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <?php if (!$inbound && $msg['reasoning']): ?>
                                <div class="reasoning-box">Why: <?= e($msg['reasoning']) ?></div>
                            <?php endif; ?>
                            <?php if (!$inbound && (int) $msg['flagged'] === 0): ?>
                                <form method="post" action="/admin/chat_history/flag" style="margin-top:8px; display:flex; gap:6px; flex-wrap:wrap"
                                      data-confirm="Flag this reply as incorrect? Eve will learn from it immediately.">
                                    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                                    <input type="hidden" name="interaction_id" value="<?= (int) $msg['id'] ?>">
                                    <input type="hidden" name="lead_id" value="<?= (int) $selectedId ?>">
                                    <select name="error_type" style="font-size:12px; padding:4px 8px; border-radius:8px; border:1.5px solid var(--belive-line)">
                                        <?php foreach (FEEDBACK_ERROR_TYPES as $type): ?>
                                            <option value="<?= e($type) ?>"><?= e(str_replace('_', ' ', $type)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" name="comment" placeholder="What's actually correct?" required
                                           style="flex:1; min-width:160px; font-size:12px; padding:4px 10px; border-radius:8px; border:1.5px solid var(--belive-line)">
                                    <button type="submit" class="belive-btn-danger" style="padding:4px 12px; font-size:12px">Flag as incorrect</button>
                                </form>
                            <?php elseif (!$inbound): ?>
                                <div style="margin-top:6px"><span class="belive-badge danger">flagged — lesson learned</span></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php admin_footer();
