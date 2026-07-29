<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Auth;
use App\Models\Booking;
use App\Models\Interaction;
use App\Models\Lead;
use App\Pipeline\Referral\ReferralLinkGenerator;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();

$lead = Lead::find((int) ($_GET['id'] ?? 0));
if ($lead === null) {
    set_flash('danger', 'Lead not found.');
    header('Location: /admin/leads');
    exit;
}

$signals = json_decode($lead['lead_signals'] ?? '[]', true) ?: [];
$transcript = Interaction::transcript((int) $lead['id'], 50);
$bookings = Booking::forLead((int) $lead['id']);
$referralLink = ReferralLinkGenerator::linkFor((int) $lead['id']);

admin_header('Lead #' . $lead['id'], 'leads');
?>
<div class="belive-page-head">
    <h1><?= e($lead['name'] ?: 'Unnamed lead') ?> <span class="belive-muted" style="font-size:16px">#<?= (int) $lead['id'] ?></span></h1>
    <a class="belive-btn-ghost" href="/admin/leads">← All leads</a>
</div>

<div class="belive-row">
    <div style="flex:0 0 340px; min-width:300px">
        <div class="belive-card">
            <div class="belive-card-title">🎯 AI assessment</div>
            <div style="margin:6px 0; font-size:13px; display:flex; justify-content:space-between">
                <span>Closing probability</span>
                <strong><?= $lead['closing_probability'] !== null ? (int) $lead['closing_probability'] . '%' : '—' ?></strong>
            </div>
            <div class="belive-bar orange"><span style="width:<?= (int) ($lead['closing_probability'] ?? 0) ?>%"></span></div>

            <?php if ($signals !== []): ?>
                <div style="margin-top:14px; font-size:13px; font-weight:600">Detected lead signals</div>
                <ul class="belive-check-list" style="margin-top:4px">
                    <?php foreach ($signals as $signal): ?><li><?= e($signal) ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if (!empty($lead['ai_recommendation'])): ?>
                <div class="reasoning-box" style="margin-top:12px"><strong>Eve recommends:</strong> <?= e($lead['ai_recommendation']) ?></div>
            <?php endif; ?>
        </div>

        <div class="belive-card" style="margin-top:16px">
            <div class="belive-card-title">📇 Details</div>
            <div class="belive-table-wrap">
                <table class="belive-table" style="font-size:13.5px">
                    <tr><td class="belive-muted">Contact</td><td><?= e($lead['wa_phone']) ?></td></tr>
                    <tr><td class="belive-muted">Channel</td><td><?= e(str_replace('_', ' ', $lead['source_channel'])) ?></td></tr>
                    <tr><td class="belive-muted">Status</td><td><span class="belive-badge <?= $lead['status'] === 'new' ? 'orange' : '' ?>"><?= e($lead['status']) ?></span></td></tr>
                    <tr><td class="belive-muted">Location</td><td><?= e($lead['location'] ?? '—') ?></td></tr>
                    <tr><td class="belive-muted">Budget</td><td><?= $lead['budget'] ? 'RM' . e($lead['budget']) : '—' ?></td></tr>
                    <tr><td class="belive-muted">Room type</td><td><?= e($lead['room_type'] ?? '—') ?></td></tr>
                    <tr><td class="belive-muted">Move-in</td><td><?= e($lead['move_in_date'] ?? '—') ?></td></tr>
                    <tr><td class="belive-muted">Profile</td><td><?= e($lead['tenant_profile'] ?? '—') ?></td></tr>
                    <tr><td class="belive-muted">First contact</td><td><?= e($lead['created_at']) ?></td></tr>
                </table>
            </div>
            <?php if (!empty($lead['notes'])): ?>
                <div class="reasoning-box" style="margin-top:10px"><?= nl2br(e($lead['notes'])) ?></div>
            <?php endif; ?>
        </div>

        <div class="belive-card" style="margin-top:16px">
            <div class="belive-card-title">🎁 Refer &amp; Earn link</div>
            <p style="font-size:13px" class="belive-muted">Share code for this customer — they earn <?= REFERRAL_REWARD_POINTS ?> points when a referred friend books.</p>
            <code style="display:block; margin-top:8px; padding:8px 10px; background:var(--belive-cream); border-radius:8px; font-size:12.5px; word-break:break-all"><?= e($referralLink) ?></code>
        </div>

        <?php if ($bookings !== []): ?>
            <div class="belive-card" style="margin-top:16px">
                <div class="belive-card-title">📅 Bookings</div>
                <?php foreach ($bookings as $booking): ?>
                    <div style="font-size:13.5px; padding:6px 0; border-bottom:1px solid var(--belive-line)">
                        <?= e($booking['viewing_datetime']) ?>
                        <span class="belive-badge <?= $booking['status'] === 'confirmed' ? '' : 'orange' ?>"><?= e($booking['status']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="belive-col">
        <div class="belive-card">
            <div class="belive-card-title">💬 Conversation</div>
            <?php if ($transcript === []): ?>
                <p class="belive-muted">No messages yet.</p>
            <?php else: ?>
                <div class="chat-thread">
                    <?php foreach ($transcript as $msg): ?>
                        <?php $inbound = $msg['direction'] === 'inbound'; ?>
                        <div class="chat-bubble <?= $inbound ? 'inbound' : 'outbound' ?>">
                            <?= nl2br(e($inbound ? $msg['message_in'] : $msg['message_out'])) ?>
                            <div class="meta">
                                <span><?= e($msg['created_at']) ?></span>
                                <?php if (!$inbound): ?><span><?= e($msg['model_used']) ?></span><?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div style="margin-top:12px">
                    <a class="belive-btn-secondary" href="/admin/chat_history?lead_id=<?= (int) $lead['id'] ?>">Open in chat history (flag replies there)</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php admin_footer();
