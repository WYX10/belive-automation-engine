<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Auth;
use App\Pipeline\LeadGeneration\LeadRepository;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();

$status = $_GET['status'] ?? null;
$channel = $_GET['channel'] ?? null;
$leads = LeadRepository::list($status, $channel);
$stats = LeadRepository::stats();

$channelIcons = [
    'whatsapp' => '💬', 'social' => '📣', 'website' => '🌐',
    'listing_portal' => '🏢', 'referral' => '🎁', 'tiktok' => '🎵',
];

admin_header('Leads', 'leads');
?>
<div class="belive-page-head">
    <h1>Leads</h1>
    <a class="belive-btn-primary" href="/admin/leads/add">+ Manual intake</a>
</div>

<div style="display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap">
    <a class="belive-badge <?= $status === null ? '' : 'muted' ?>" href="/admin/leads">all (<?= $stats['total'] ?>)</a>
    <?php foreach (LEAD_STATUSES as $s): ?>
        <a class="belive-badge <?= $status === $s ? 'orange' : 'muted' ?>" href="/admin/leads?status=<?= e($s) ?>">
            <?= e($s) ?> (<?= $stats['by_status'][$s] ?? 0 ?>)
        </a>
    <?php endforeach; ?>
    <span style="width:12px"></span>
    <?php foreach (LEAD_SOURCE_CHANNELS as $c): ?>
        <?php if (($stats['by_channel'][$c] ?? 0) > 0): ?>
            <a class="belive-badge <?= $channel === $c ? 'orange' : 'muted' ?>" href="/admin/leads?channel=<?= e($c) ?>">
                <?= $channelIcons[$c] ?> <?= e(str_replace('_', ' ', $c)) ?> (<?= $stats['by_channel'][$c] ?>)
            </a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>

<div class="belive-card">
    <?php if ($leads === []): ?>
        <p class="belive-muted">No leads match this filter yet.</p>
    <?php else: ?>
        <table class="belive-table">
            <thead>
            <tr><th>Lead</th><th>Channel</th><th>Enquiry</th><th>Closing %</th><th>Status</th><th>Last contact</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($leads as $lead): ?>
                <tr>
                    <td>
                        <strong><?= e($lead['name'] ?: 'Unnamed') ?></strong>
                        <div class="belive-muted" style="font-size:12px"><?= e($lead['wa_phone']) ?></div>
                    </td>
                    <td><?= $channelIcons[$lead['source_channel']] ?? '' ?> <?= e(str_replace('_', ' ', $lead['source_channel'])) ?></td>
                    <td style="font-size:13px; max-width:220px">
                        <?= e(implode(' · ', array_filter([
                            $lead['location'],
                            $lead['room_type'] ? $lead['room_type'] . ' room' : null,
                            $lead['budget'] ? 'RM' . $lead['budget'] : null,
                        ]))) ?: '<span class="belive-muted">—</span>' ?>
                    </td>
                    <td style="min-width:110px">
                        <?php if ($lead['closing_probability'] !== null): ?>
                            <div style="display:flex; align-items:center; gap:8px">
                                <div class="belive-bar orange" style="flex:1"><span style="width:<?= (int) $lead['closing_probability'] ?>%"></span></div>
                                <strong style="font-size:13px"><?= (int) $lead['closing_probability'] ?>%</strong>
                            </div>
                        <?php else: ?>
                            <span class="belive-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="belive-badge <?= $lead['status'] === 'new' ? 'orange' : '' ?>"><?= e($lead['status']) ?></span></td>
                    <td style="font-size:13px; white-space:nowrap"><?= e($lead['last_contact_at'] ?? $lead['created_at']) ?></td>
                    <td><a class="belive-btn-ghost" style="padding:5px 12px; font-size:13px" href="/admin/leads/view?id=<?= (int) $lead['id'] ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php admin_footer();
