<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Auth;
use App\Core\Database;
use App\Models\Booking;
use App\Models\Interaction;
use App\Models\Lead;

require __DIR__ . '/_layout.php';
Auth::requireAdmin();

// --- The exact six metrics from the proposal's own dashboard mockup ---------
$totalReplies = Interaction::totalReplies();
$leadsToday = Lead::capturedToday();
$bookingsConfirmed = Booking::confirmedCount();
$postsToday = (int) Database::run(
    "SELECT COUNT(*) FROM content_posts WHERE DATE(created_at) = CURDATE()"
)->fetchColumn();
$avgMs = Interaction::avgResponseMs();
$avgDisplay = $avgMs === null ? '—' : ($avgMs >= 1000 ? round($avgMs / 1000, 1) . 's' : $avgMs . 'ms');
$totalEngagement = (int) Database::run(
    "SELECT COUNT(*) FROM ai_interactions WHERE direction IN ('inbound','outbound')"
)->fetchColumn();

// --- Live lead detail: most recently active lead with an AI assessment -------
$liveLead = Database::run(
    'SELECT * FROM leads ORDER BY last_contact_at DESC, id DESC LIMIT 1'
)->fetch() ?: null;
$liveSignals = $liveLead ? (json_decode($liveLead['lead_signals'] ?? '[]', true) ?: []) : [];
$recentForLead = $liveLead ? Interaction::transcript((int) $liveLead['id'], 6) : [];

$stats = [
    ['📨', 'Total AI replies',       number_format($totalReplies),      ''],
    ['👥', 'Leads captured today',   number_format($leadsToday),        'teal'],
    ['📅', 'Bookings confirmed',     number_format($bookingsConfirmed), ''],
    ['📣', 'Social posts today',     number_format($postsToday),        'teal'],
    ['⚡', 'Avg AI response time',   $avgDisplay,                       ''],
    ['🔁', 'Total engagement',       number_format($totalEngagement),   'teal'],
];

admin_header('Dashboard', 'dashboard');
?>
<div class="belive-page-head">
    <h1>Dashboard</h1>
    <span class="belive-badge">Eve · live</span>
</div>

<div class="belive-stat-grid" style="margin-bottom:22px">
    <?php foreach ($stats as [$icon, $label, $value, $tone]): ?>
        <div class="belive-stat">
            <div class="belive-stat-icon <?= e($tone) ?>"><?= $icon ?></div>
            <div>
                <div class="belive-stat-number <?= e($tone) ?>"><?= e($value) ?></div>
                <div class="belive-stat-label"><?= e($label) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="belive-row">
    <div class="belive-col">
        <div class="belive-card">
            <div class="belive-card-title">🎯 Live lead detail</div>
            <?php if ($liveLead === null): ?>
                <p class="belive-muted">No leads yet — they'll appear here the moment Eve captures one
                (WhatsApp, website form, social, referral, or manual intake).</p>
            <?php else: ?>
                <div style="display:flex; justify-content:space-between; align-items:baseline; gap:10px; flex-wrap:wrap">
                    <div>
                        <strong><?= e($liveLead['name'] ?: 'Unnamed lead') ?></strong>
                        <span class="belive-muted" style="font-size:13px"> · <?= e($liveLead['wa_phone']) ?></span>
                    </div>
                    <span class="belive-badge <?= $liveLead['status'] === 'new' ? 'orange' : '' ?>"><?= e($liveLead['status']) ?></span>
                </div>

                <div style="margin:14px 0 6px; font-size:13px; display:flex; justify-content:space-between">
                    <span>Closing probability</span>
                    <strong><?= $liveLead['closing_probability'] !== null ? e($liveLead['closing_probability']) . '%' : '—' ?></strong>
                </div>
                <div class="belive-bar orange"><span style="width:<?= (int) ($liveLead['closing_probability'] ?? 0) ?>%"></span></div>

                <?php if ($liveSignals !== []): ?>
                    <div style="margin-top:14px; font-size:13px; font-weight:600">Detected lead signals</div>
                    <ul class="belive-check-list" style="margin-top:4px">
                        <?php foreach ($liveSignals as $signal): ?>
                            <li><?= e($signal) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if (!empty($liveLead['ai_recommendation'])): ?>
                    <div class="reasoning-box" style="margin-top:12px">
                        <strong>Eve recommends:</strong> <?= e($liveLead['ai_recommendation']) ?>
                    </div>
                <?php endif; ?>

                <div style="margin-top:14px">
                    <a class="belive-btn-secondary" href="/admin/leads/view?id=<?= (int) $liveLead['id'] ?>">Open lead</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="belive-col">
        <div class="belive-card">
            <div class="belive-card-title">💬 Latest conversation</div>
            <?php if ($recentForLead === []): ?>
                <p class="belive-muted">No messages yet. Once WhatsApp is connected, live conversations
                show here in real time.</p>
            <?php else: ?>
                <div class="chat-thread">
                    <?php foreach ($recentForLead as $msg): ?>
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
            <?php endif; ?>
        </div>
    </div>
</div>
<?php admin_footer();
