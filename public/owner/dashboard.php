<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

/**
 * Owner dashboard — mirrors the four things belive.asia already promises
 * owners: repairs, expenses, occupancy, returns.
 *
 * Occupancy, returns and utilities are real aggregates over live tables:
 * electricity comes from the per-room submeters (electric_meters /
 * electric_bills), shown against the house each room sits in. Door access and
 * repairs would be fed by BeLive's EXISTING IoT/ops systems, so they stay as
 * integration-ready surfaces with a documented input contract
 * (docs/api_reference.md) — never simulated live readings.
 */

use App\Core\Database;
use App\Models\DigitalAgreement;
use App\Models\ElectricBill;

require dirname(__DIR__) . '/_portal_layout.php';
$owner = require_owner();

// Income figures use the 12-month tenure rate (the committed-tenancy rate).
$rooms = Database::run(
    "SELECT r.*, COALESCE(rp.price, 0) AS price
     FROM rooms r
     LEFT JOIN room_pricing rp ON rp.room_id = r.id AND rp.tenure = '12_month'
     WHERE r.owner_name = ? ORDER BY r.location",
    [$owner]
)->fetchAll();
$roomIds = array_map(fn ($r) => (int) $r['id'], $rooms);
$idList = $roomIds === [] ? '0' : implode(',', $roomIds);

// REAL aggregates ------------------------------------------------------------
$occupied = count(array_filter($rooms, fn ($r) => $r['status'] === 'occupied'));
$occupancyPct = $rooms === [] ? 0 : (int) round($occupied / count($rooms) * 100);
$monthlyPotential = array_sum(array_map(fn ($r) => (float) $r['price'], $rooms));
$monthlyActual = array_sum(array_map(fn ($r) => $r['status'] === 'occupied' ? (float) $r['price'] : 0, $rooms));
$upcomingViewings = (int) Database::run(
    "SELECT COUNT(*) FROM bookings WHERE room_id IN ($idList) AND status IN ('pending','confirmed') AND viewing_datetime >= NOW()"
)->fetchColumn();

// Tenancies running out — the same rule the Tenants & renewals page groups by.
$endingSoon = 0;
foreach (DigitalAgreement::tenanciesForOwner($owner) as $tenancy) {
    $state = DigitalAgreement::timeline($tenancy)['state'] ?? null;
    if ($state === 'ending_soon' || $state === 'ending_today') {
        $endingSoon++;
    }
}

// Per-room electricity, with the house each room sits in. No tenant identity.
$utilities = ElectricBill::latestForOwnerRooms($owner);

portal_header('owner', 'Overview', 'dashboard');
?>
<div class="portal-hero">
    <div class="tagline">Higher Returns. Zero Stress. Smarter Rentals.</div>
    <h1>Welcome back, <?= e($owner) ?></h1>
    <p>Track repairs, expenses, occupancy and returns — in one dashboard.</p>
</div>

<div class="belive-stat-grid" style="margin-bottom:22px">
    <div class="belive-stat">
        <div class="belive-stat-icon">🏠</div>
        <div><div class="belive-stat-number"><?= count($rooms) ?></div><div class="belive-stat-label">listings</div></div>
    </div>
    <div class="belive-stat">
        <div class="belive-stat-icon teal">📈</div>
        <div><div class="belive-stat-number teal"><?= $occupancyPct ?>%</div><div class="belive-stat-label">occupancy rate</div></div>
    </div>
    <div class="belive-stat">
        <div class="belive-stat-icon">💰</div>
        <div><div class="belive-stat-number">RM <?= e(number_format($monthlyActual)) ?></div><div class="belive-stat-label">occupied monthly income at 12-month rates (of RM <?= e(number_format($monthlyPotential)) ?> potential)</div></div>
    </div>
    <div class="belive-stat">
        <div class="belive-stat-icon teal">📅</div>
        <div><div class="belive-stat-number teal"><?= $upcomingViewings ?></div><div class="belive-stat-label">upcoming viewings (Eve-booked)</div></div>
    </div>
    <a class="belive-stat" href="/owner/tenancies">
        <div class="belive-stat-icon">⏳</div>
        <div><div class="belive-stat-number"><?= $endingSoon ?></div><div class="belive-stat-label">tenancies ending within <?= DigitalAgreement::ENDING_SOON_DAYS ?> days — offer a renewal price</div></div>
    </a>
</div>

<div class="belive-row">
    <div class="belive-col">
        <div class="belive-card">
            <div class="belive-card-title">📊 Occupancy &amp; returns by area <span class="belive-badge">live data</span></div>
            <div class="belive-table-wrap">
                <table class="belive-table">
                    <thead><tr><th>Area</th><th>Rooms</th><th>Occupied</th><th>Monthly income</th></tr></thead>
                    <tbody>
                    <?php
                    $byArea = [];
                    foreach ($rooms as $room) {
                        $area = $room['location'];
                        $byArea[$area]['n'] = ($byArea[$area]['n'] ?? 0) + 1;
                        $byArea[$area]['occ'] = ($byArea[$area]['occ'] ?? 0) + ($room['status'] === 'occupied' ? 1 : 0);
                        $byArea[$area]['rm'] = ($byArea[$area]['rm'] ?? 0) + ($room['status'] === 'occupied' ? (float) $room['price'] : 0);
                    }
                    ?>
                    <?php foreach ($byArea as $area => $stat): ?>
                        <tr>
                            <td><?= e($area) ?></td>
                            <td><?= (int) $stat['n'] ?></td>
                            <td><?= (int) $stat['occ'] ?></td>
                            <td>RM <?= e(number_format($stat['rm'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($byArea === []): ?><tr><td colspan="4" class="belive-muted">No listings yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="belive-card sample-panel" style="margin-top:16px">
            <div class="belive-card-title">🔧 Repairs &amp; maintenance</div>
            <div class="belive-table-wrap">
                <table class="belive-table">
                    <thead><tr><th>Unit</th><th>Issue</th><th>Status</th></tr></thead>
                    <tbody>
                        <tr><td>A-12-3</td><td>Aircon service (scheduled)</td><td><span class="belive-badge orange">in progress</span></td></tr>
                        <tr><td>B-8-1</td><td>WiFi router replacement</td><td><span class="belive-badge">done</span></td></tr>
                    </tbody>
                </table>
            </div>
            <p class="belive-muted" style="font-size:12px; margin-top:8px">
                Input contract: BeLive's existing maintenance system POSTs
                <code>{unit, issue, status, updated_at}</code> — this panel renders that feed directly.
            </p>
        </div>
    </div>

    <div class="belive-col">
        <div class="belive-card">
            <div class="belive-card-title">⚡ Utilities &amp; access <span class="belive-badge">live data</span></div>
            <div class="belive-table-wrap">
                <table class="belive-table utilities-table">
                    <thead><tr><th>House</th><th>Room</th><th>Electricity (kWh)</th><th>Last door access</th></tr></thead>
                    <tbody>
                    <?php foreach ($utilities as $unit): ?>
                        <tr>
                            <td>
                                <?= e($unit['house_name'] ?? 'No house set') ?>
                                <?php if ($unit['property_name'] !== null): ?>
                                    <div class="belive-muted" style="font-size:11.5px"><?= e($unit['property_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= e($unit['room_name']) ?>
                                <?php if ($unit['room_code'] !== null): ?>
                                    <div class="belive-muted" style="font-size:11.5px"><?= e($unit['room_code']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($unit['units_kwh'] !== null): ?>
                                    <strong><?= e(number_format((float) $unit['units_kwh'], 1)) ?></strong>
                                    <div class="belive-muted" style="font-size:11.5px">
                                        <?= e(date('j M', strtotime((string) $unit['period_start']))) ?>–<?= e(date('j M Y', strtotime((string) $unit['period_end']))) ?>
                                        · <?= $unit['reading_source'] === 'smart_meter' ? 'smart meter' : 'manual read' ?>
                                    </div>
                                <?php elseif ($unit['meter_serial'] !== null): ?>
                                    <span class="belive-muted">No bill issued yet</span>
                                <?php else: ?>
                                    <span class="belive-muted">No meter registered</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="belive-muted">Not connected</span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($utilities === []): ?>
                        <tr><td colspan="4" class="belive-muted">No rooms yet — add one on Properties &amp; rooms.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <p class="belive-muted" style="font-size:12px; margin-top:8px">
                Electricity is live: every room has its own submeter, so these are the units that room actually
                used in its last closed billing period — the same figures the tenant sees. Door access is not
                wired up yet; BeLive's existing smart locks plug in through
                <code>{room_code, last_access_at}</code> and fill that column. We build zero hardware.
            </p>
        </div>

        <div class="belive-card" style="margin-top:16px">
            <div class="belive-card-title">💸 Fees &amp; expenses <span class="belive-badge">live data</span></div>
            <?php $mgmtFee = round($monthlyActual * 0.10, 2); ?>
            <div class="belive-table-wrap">
                <table class="belive-table">
                    <tbody>
                        <tr><td>Occupied rental income (monthly)</td><td style="text-align:right">RM <?= e(number_format($monthlyActual, 2)) ?></td></tr>
                        <tr><td>BeLive management fee (10%)</td><td style="text-align:right">− RM <?= e(number_format($mgmtFee, 2)) ?></td></tr>
                        <tr><td><strong>Net to owner</strong></td><td style="text-align:right"><strong>RM <?= e(number_format($monthlyActual - $mgmtFee, 2)) ?></strong></td></tr>
                    </tbody>
                </table>
            </div>
            <p class="belive-muted" style="font-size:12px; margin-top:8px">Computed from your live listings; fee rate illustrative for the demo.</p>
        </div>
    </div>
</div>
<?php portal_footer();
