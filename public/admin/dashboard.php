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
// Leads and posts are counted for the calendar month to date: a single day's
// number swings too hard to read a trend from on demo day or in a real week.
$totalReplies = Interaction::totalReplies();
$leadsThisMonth = Lead::capturedThisMonth();
$bookingsConfirmed = Booking::confirmedCount();
$postsThisMonth = (int) Database::run(
    "SELECT COUNT(*) FROM content_posts WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
)->fetchColumn();
$avgMs = Interaction::avgResponseMs();
$avgDisplay = $avgMs === null ? '—' : ($avgMs >= 1000 ? round($avgMs / 1000, 1) . 's' : $avgMs . 'ms');
$totalEngagement = (int) Database::run(
    "SELECT COUNT(*) FROM ai_interactions WHERE direction IN ('inbound','outbound')"
)->fetchColumn();

// --- Live lead detail: the ten leads most likely to close, each carrying its
// own transcript behind a button so the busiest lead never buries the rest.
$liveLeads = Lead::hottest(10);
$transcripts = [];
foreach ($liveLeads as $lead) {
    $transcripts[(int) $lead['id']] = Interaction::transcript((int) $lead['id'], 6);
}

$monthLabel = date('M Y');
$stats = [
    ['📨', 'Total AI replies',       number_format($totalReplies),      ''],
    ['👥', 'Leads captured this month',   number_format($leadsThisMonth),    'teal'],
    ['📅', 'Bookings confirmed',     number_format($bookingsConfirmed), ''],
    ['📣', 'Social posts this month',     number_format($postsThisMonth),    'teal'],
    ['⚡', 'Avg AI response time',   $avgDisplay,                       ''],
    ['🔁', 'Total engagement',       number_format($totalEngagement),   'teal'],
];

admin_header('Dashboard', 'dashboard');
?>
<div class="belive-page-head">
    <div>
        <h1>Dashboard</h1>
        <p class="belive-muted" style="font-size:13px">Monthly counters cover <?= e($monthLabel) ?> to date.</p>
    </div>
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

<div class="belive-card">
    <div class="belive-card-title">🎯 Live lead detail <span class="belive-badge muted">top <?= count($liveLeads) ?></span></div>
    <?php if ($liveLeads === []): ?>
        <p class="belive-muted">No leads yet — they'll appear here the moment Eve captures one
        (WhatsApp, website form, social, referral, or manual intake).</p>
    <?php else: ?>
        <p class="belive-muted" style="font-size:13px">The ten leads Eve rates most likely to close,
        highest closing probability first. Open a conversation to read exactly what Eve said,
        without leaving this page.</p>
        <div class="dash-lead-list">
            <?php foreach ($liveLeads as $lead): ?>
                <?php
                $leadId = (int) $lead['id'];
                $signals = json_decode($lead['lead_signals'] ?? '[]', true) ?: [];
                $thread = $transcripts[$leadId] ?? [];
                $probability = $lead['closing_probability'] !== null ? (int) $lead['closing_probability'] : null;
                ?>
                <article class="dash-lead">
                    <div class="dash-lead-head">
                        <div>
                            <strong><?= e($lead['name'] ?: 'Unnamed lead') ?></strong>
                            <span class="belive-muted" style="font-size:13px"> · <?= e($lead['wa_phone']) ?></span>
                            <div class="belive-muted" style="font-size:12px">
                                <?= e($lead['source_channel']) ?>
                                <?php if ($lead['last_contact_at']): ?> · last active <?= e($lead['last_contact_at']) ?><?php endif; ?>
                            </div>
                        </div>
                        <span class="belive-badge <?= $lead['status'] === 'new' ? 'orange' : '' ?>"><?= e($lead['status']) ?></span>
                    </div>

                    <div style="margin:12px 0 6px; font-size:13px; display:flex; justify-content:space-between">
                        <span>Closing probability</span>
                        <strong><?= $probability !== null ? e((string) $probability) . '%' : '—' ?></strong>
                    </div>
                    <div class="belive-bar orange"><span style="width:<?= (int) $probability ?>%"></span></div>

                    <?php if ($signals !== []): ?>
                        <div style="margin-top:12px; font-size:13px; font-weight:600">Detected lead signals</div>
                        <ul class="belive-check-list" style="margin-top:4px">
                            <?php foreach ($signals as $signal): ?>
                                <li><?= e((string) $signal) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if (!empty($lead['ai_recommendation'])): ?>
                        <div class="reasoning-box" style="margin-top:12px">
                            <strong>Eve recommends:</strong> <?= e($lead['ai_recommendation']) ?>
                        </div>
                    <?php endif; ?>

                    <div class="dash-lead-actions">
                        <a class="belive-btn-secondary" href="/admin/leads/view?id=<?= $leadId ?>">Open lead</a>
                        <details class="dash-lead-convo">
                            <summary class="belive-btn-ghost">💬 View conversation (<?= count($thread) ?>)</summary>
                            <?php if ($thread === []): ?>
                                <p class="belive-muted" style="font-size:13px; margin-top:10px">No messages logged for
                                this lead yet.</p>
                            <?php else: ?>
                                <div class="chat-thread" style="margin-top:12px">
                                    <?php foreach ($thread as $msg): ?>
                                        <?php $inbound = $msg['direction'] === 'inbound'; ?>
                                        <div class="chat-bubble <?= $inbound ? 'inbound' : 'outbound' ?>">
                                            <?= nl2br(e((string) ($inbound ? $msg['message_in'] : $msg['message_out']))) ?>
                                            <div class="meta">
                                                <span><?= e($msg['created_at']) ?></span>
                                                <?php if (!$inbound): ?><span><?= e($msg['model_used']) ?></span><?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </details>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php admin_footer();
