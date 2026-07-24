<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

/**
 * Tenant electricity view — every BeLive room has its own meter, so this page
 * answers the one question a shared-house tenant can never usually answer:
 * what did MY room use, and what am I paying for it?
 *
 * Read-only and strictly own-bills-only (ElectricBill scopes by lead_id).
 * Every figure on the page comes from a stored bill; nothing is estimated.
 */

use App\Models\ElectricBill;

require dirname(__DIR__) . '/_portal_layout.php';
$lead = require_tenant();
$room = tenant_room($lead);
$meter = $room !== null ? ElectricBill::meterForRoom((int) $room['id']) : null;

$summary = ElectricBill::summaryForTenant((int) $lead['id']);
$bills = $summary['bills'];
$latest = $summary['latest'];
$series = ElectricBill::usageSeries($bills);
$peakKwh = 0.0;
foreach ($series as $point) {
    $peakKwh = max($peakKwh, (float) $point['units_kwh']);
}

$statusBadge = static function (array $bill): array {
    if (ElectricBill::isOverdue($bill)) {
        return ['overdue', 'danger'];
    }

    return match ($bill['status']) {
        'paid'   => ['paid', ''],
        'waived' => ['waived', 'muted'],
        default  => ['unpaid', 'orange'],
    };
};

portal_header('tenant', 'Electric bill', 'electric');
?>
<div class="portal-hero">
    <div class="tagline">Your room. Your meter. Your bill.</div>
    <h1>Electricity</h1>
    <p>Every BeLive room has its own meter, so you pay for the units your room actually used — never a split of someone else's aircon.</p>
</div>

<?php if ($room === null): ?>
    <div class="belive-card">
        <p class="belive-muted">Your electricity view appears here once a room is attached to your booking.</p>
    </div>
<?php elseif ($meter === null && $bills === []): ?>
    <div class="belive-card">
        <div class="belive-card-title">⚡ <?= e($room['name']) ?></div>
        <p class="belive-muted">No meter is registered for this room yet, so there is nothing to bill you for.
        Ask Eve if you believe that's wrong — you should never be charged for units we can't show you.</p>
        <p style="margin-top:14px"><a class="belive-btn-secondary" href="<?= e(eve_whatsapp_link()) ?>" target="_blank" rel="noopener">💬 Ask Eve about my meter</a></p>
    </div>
<?php else: ?>

    <?php if ($summary['outstanding_count'] > 0): ?>
        <div class="belive-alert <?= $summary['overdue_count'] > 0 ? 'danger' : 'warning' ?>">
            <?php if ($summary['overdue_count'] > 0): ?>
                <strong>RM <?= e(number_format($summary['outstanding_rm'], 2)) ?> outstanding</strong> across
                <?= (int) $summary['outstanding_count'] ?> bill<?= $summary['outstanding_count'] === 1 ? '' : 's' ?> —
                <?= (int) $summary['overdue_count'] ?> past its due date. Settle with your BeLive contact or ask Eve for the payment details.
            <?php else: ?>
                <strong>RM <?= e(number_format($summary['outstanding_rm'], 2)) ?> due</strong> by
                <?= e(date('j M Y', strtotime((string) $summary['next_due_on']))) ?>.
            <?php endif; ?>
        </div>
    <?php elseif ($bills !== []): ?>
        <div class="belive-alert success">✓ <strong>Nothing outstanding.</strong> Every electricity bill on your room is settled.</div>
    <?php endif; ?>

    <div class="belive-stat-grid" style="margin-bottom:16px">
        <div class="belive-stat">
            <div class="belive-stat-icon">💡</div>
            <div>
                <div class="belive-stat-number">RM <?= e(number_format($summary['outstanding_rm'], 2)) ?></div>
                <div class="belive-stat-label">outstanding right now</div>
            </div>
        </div>
        <div class="belive-stat">
            <div class="belive-stat-icon teal">⚡</div>
            <div>
                <div class="belive-stat-number teal"><?= $latest !== null ? e(number_format((float) $latest['units_kwh'], 1)) : '—' ?></div>
                <div class="belive-stat-label">kWh in your latest period</div>
            </div>
        </div>
        <div class="belive-stat">
            <div class="belive-stat-icon teal">📊</div>
            <div>
                <div class="belive-stat-number teal"><?= $summary['average_kwh'] !== null ? e(number_format($summary['average_kwh'], 1)) : '—' ?></div>
                <div class="belive-stat-label">kWh average across <?= count($bills) ?> billed period<?= count($bills) === 1 ? '' : 's' ?></div>
            </div>
        </div>
        <div class="belive-stat">
            <div class="belive-stat-icon">🏷</div>
            <div>
                <div class="belive-stat-number">RM <?= e(number_format((float) ($meter['tariff_rm_per_kwh'] ?? ($latest['rate_rm_per_kwh'] ?? 0)), 2)) ?></div>
                <div class="belive-stat-label">per kWh on your meter</div>
            </div>
        </div>
    </div>

    <?php if ($bills === []): ?>
        <div class="belive-card">
            <div class="belive-card-title">⚡ Meter <?= e($meter['meter_serial']) ?> · <?= e($room['name']) ?></div>
            <p class="belive-muted">Your meter is registered and reading. Your first bill appears here at the end of
            your first billing period — with both readings shown, so you can check it against the meter yourself.</p>
        </div>
    <?php else: ?>

        <?php if ($series !== [] && $peakKwh > 0): ?>
            <?php
            $chartLabel = [];
            foreach ($series as $point) {
                $chartLabel[] = date('M Y', strtotime($point['period_end'])) . ': ' . number_format((float) $point['units_kwh'], 1) . ' kWh';
            }
            ?>
            <div class="belive-card">
                <div class="belive-card-title">📈 Your usage, month by month</div>
                <div class="electric-chart" role="img" aria-label="Electricity used per billing period — <?= e(implode('; ', $chartLabel)) ?>">
                    <?php foreach ($series as $point): ?>
                        <?php $height = max(4, (int) round((float) $point['units_kwh'] / $peakKwh * 100)); ?>
                        <div class="electric-chart-col">
                            <span class="electric-chart-value"><?= e(number_format((float) $point['units_kwh'], 0)) ?></span>
                            <div class="electric-chart-bar"><span style="height:<?= $height ?>%"></span></div>
                            <span class="electric-chart-label"><?= e(date('M', strtotime($point['period_end']))) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="belive-muted" style="font-size:12px; margin-top:12px">
                    kWh used per billing period, from your own meter readings.
                    <?php if ($summary['change_kwh'] !== null && $summary['change_kwh'] != 0.0): ?>
                        Your latest period used <strong><?= e(number_format(abs((float) $summary['change_kwh']), 1)) ?> kWh
                        <?= $summary['change_kwh'] > 0 ? 'more' : 'less' ?></strong> than the one before.
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>

        <div class="belive-card">
            <div class="belive-card-title">🧾 Your bills</div>
            <div class="electric-table-wrap">
                <table class="belive-table electric-bill-table">
                    <thead>
                        <tr>
                            <th>Billing period</th>
                            <th>Meter readings</th>
                            <th>Units</th>
                            <th>Rate</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($bills as $bill): ?>
                        <?php [$badgeLabel, $badgeClass] = $statusBadge($bill); ?>
                        <tr>
                            <td>
                                <strong><?= e(date('j M', strtotime($bill['period_start']))) ?> – <?= e(date('j M Y', strtotime($bill['period_end']))) ?></strong>
                                <span class="electric-sub"><?= e($bill['room_name'] ?? 'Room') ?> · meter <?= e($bill['meter_serial']) ?></span>
                            </td>
                            <td>
                                <?= e(number_format((float) $bill['previous_reading'], 1)) ?> → <?= e(number_format((float) $bill['current_reading'], 1)) ?>
                                <span class="electric-sub"><?= $bill['reading_source'] === 'smart_meter' ? 'smart meter reading' : 'manual meter read' ?></span>
                            </td>
                            <td class="electric-num"><?= e(number_format((float) $bill['units_kwh'], 1)) ?> kWh</td>
                            <td class="electric-num">
                                RM <?= e(number_format((float) $bill['rate_rm_per_kwh'], 2)) ?>
                                <?php if ((float) $bill['standing_charge_rm'] > 0): ?>
                                    <span class="electric-sub">+ RM <?= e(number_format((float) $bill['standing_charge_rm'], 2)) ?> standing</span>
                                <?php endif; ?>
                            </td>
                            <td class="electric-num"><strong>RM <?= e(number_format((float) $bill['amount_rm'], 2)) ?></strong></td>
                            <td>
                                <span class="belive-badge <?= e($badgeClass) ?>"><?= e($badgeLabel) ?></span>
                                <span class="electric-sub">
                                    <?php if ($bill['status'] === 'paid' && $bill['paid_at'] !== null): ?>
                                        paid <?= e(date('j M Y', strtotime($bill['paid_at']))) ?>
                                    <?php elseif ($bill['status'] === 'unpaid'): ?>
                                        due <?= e(date('j M Y', strtotime($bill['due_on']))) ?>
                                    <?php endif; ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="belive-muted" style="font-size:12px; margin-top:12px">
                Every bill shows both readings it was worked out from — walk to your meter and check it yourself.
                Something not matching? <a href="<?= e(eve_whatsapp_link()) ?>" target="_blank" rel="noopener">Message Eve</a> and we'll re-read it.
            </p>
        </div>
    <?php endif; ?>

    <?php if ($meter !== null): ?>
        <div class="belive-card">
            <div class="belive-card-title">🔌 Your meter</div>
            <div class="electric-meter-facts">
                <div><span>Meter serial</span><strong><?= e($meter['meter_serial']) ?></strong></div>
                <div><span>Fitted to</span><strong><?= e($room['name']) ?><?= $room['property_name'] ? ' · ' . e($room['property_name']) : '' ?></strong></div>
                <div><span>Rate</span><strong>RM <?= e(number_format((float) $meter['tariff_rm_per_kwh'], 2)) ?> / kWh</strong></div>
                <div>
                    <span>Standing charge</span>
                    <strong><?= (float) $meter['standing_charge_rm'] > 0 ? 'RM ' . e(number_format((float) $meter['standing_charge_rm'], 2)) . ' / period' : 'None' ?></strong>
                </div>
            </div>
            <p class="belive-muted" style="font-size:12px; margin-top:14px">
                How your bill is worked out: <code>(current reading − previous reading) × rate<?= (float) $meter['standing_charge_rm'] > 0 ? ' + standing charge' : '' ?></code>.
                The rate on each bill is the one that applied when it was issued, so a later change never rewrites a bill you've already seen.
            </p>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php portal_footer();
