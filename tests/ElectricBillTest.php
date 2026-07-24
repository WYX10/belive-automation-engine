<?php

declare(strict_types=1);

/**
 * Per-room electricity submetering: a bill is worked out once from two meter
 * readings and stored complete, a tenant only ever sees the bills issued to
 * them, and the portal summary adds up what is genuinely owed.
 *
 * Every period is anchored to the month this runs in, so the suite cannot
 * start failing when a hardcoded due date drifts into the past.
 */

use App\Models\ElectricBill;
use App\Models\Lead;
use App\Models\Room;

/** First/last day of the calendar month $ago months back. */
$monthStart = static fn (int $ago): string => date('Y-m-01', strtotime(date('Y-m-01') . " -$ago month"));
$monthEnd = static fn (int $ago): string => date('Y-m-t', strtotime(date('Y-m-01') . " -$ago month"));
$inDays = static fn (int $days): string => date('Y-m-d', strtotime("+$days day"));

$roomId = Room::create([
    'room_code' => 'ELEC-01',
    'name'      => 'Meter Test Room',
    'location'  => 'Metering Test Park',
    'room_type' => 'single',
    'status'    => 'occupied',
]);
$otherRoomId = Room::create([
    'room_code' => 'ELEC-02',
    'name'      => 'Neighbour Room',
    'location'  => 'Metering Test Park',
    'room_type' => 'single',
]);

$tenant = Lead::create(['wa_phone' => '60129990801', 'name' => 'Meter Tenant', 'source_channel' => 'whatsapp']);
$neighbour = Lead::create(['wa_phone' => '60129990802', 'name' => 'Previous Tenant', 'source_channel' => 'whatsapp']);

// ---- the meter --------------------------------------------------------------
$meter = ElectricBill::registerMeter($roomId, 'TEST-MTR-01', 0.55, 3.00, $monthStart(6));
check('a room gets its own meter', ElectricBill::meterForRoom($roomId)['meter_serial'] === 'TEST-MTR-01');
check('a room without a meter reports none', ElectricBill::meterForRoom($otherRoomId) === null);

$rejectedTariff = false;
try {
    ElectricBill::registerMeter($otherRoomId, 'TEST-MTR-02', 0.0);
} catch (InvalidArgumentException) {
    $rejectedTariff = true;
}
check('a meter cannot bill at RM 0.00 per kWh', $rejectedTariff);

$meterId = (int) $meter['id'];
$issue = static fn (array $extra): array => ElectricBill::issue(array_replace([
    'meter_id' => $meterId,
    'lead_id'  => $tenant,
], $extra));

// ---- the arithmetic ---------------------------------------------------------
$paidIssuedOn = date('Y-m-d', strtotime($monthEnd(3) . ' +2 day'));
$paid = $issue([
    'period_start'     => $monthStart(3),
    'period_end'       => $monthEnd(3),
    'previous_reading' => 1000,
    'current_reading'  => 1128.5,
    'reading_source'   => 'smart_meter',
    'issued_on'        => $paidIssuedOn,
    'status'           => 'paid',
    'paid_at'          => date('Y-m-d H:i:s', strtotime($paidIssuedOn . ' +6 day')),
]);

check('units are the difference between the two readings', (float) $paid['units_kwh'] === 128.5);
// 128.5 kWh × RM0.55 = RM70.68, plus the RM3.00 standing charge.
check('amount is units × rate + standing charge', (float) $paid['amount_rm'] === 73.68, $paid['amount_rm']);
check('the meter rate is snapshotted onto the bill', (float) $paid['rate_rm_per_kwh'] === 0.55);
check('the standing charge is snapshotted too', (float) $paid['standing_charge_rm'] === 3.0);
check('the due date defaults to the payment term after issue',
    $paid['due_on'] === date('Y-m-d', strtotime($paidIssuedOn . ' +' . ElectricBill::PAYMENT_TERM_DAYS . ' day')),
    $paid['due_on']);

// A later re-tariff must not rewrite a bill the tenant has already been shown.
ElectricBill::registerMeter($roomId, 'TEST-MTR-01', 0.62, 3.00, $monthStart(6));
check('re-tariffing the meter leaves issued bills untouched',
    (float) ElectricBill::find((int) $paid['id'])['rate_rm_per_kwh'] === 0.55);

$open = $issue([
    'period_start'     => $monthStart(2),
    'period_end'       => $monthEnd(2),
    'previous_reading' => 1128.5,
    'current_reading'  => 1290.5,
    'due_on'           => $inDays(6),
]);
check('the new rate applies to the next bill', (float) $open['rate_rm_per_kwh'] === 0.62);
check('a bill defaults to unpaid with no payment timestamp',
    $open['status'] === 'unpaid' && $open['paid_at'] === null);

// ---- what a bill refuses to be ----------------------------------------------
// Base case: this month's period, which overlaps nothing issued so far.
$refused = static function (array $extra) use ($issue, $monthStart, $monthEnd): bool {
    try {
        $issue(array_replace([
            'period_start'     => $monthStart(0),
            'period_end'       => $monthEnd(0),
            'previous_reading' => 1290.5,
            'current_reading'  => 1400.0,
        ], $extra));
    } catch (InvalidArgumentException) {
        return true;
    }

    return false;
};

check('a meter cannot run backwards', $refused(['current_reading' => 1200.0]));
check('a period must end after it starts', $refused(['period_end' => $monthStart(0)]));
check('a non-numeric reading is refused', $refused(['current_reading' => 'about 1400']));
check('an unknown tenant is refused', $refused(['lead_id' => 999999]));
check('an unknown meter is refused', $refused(['meter_id' => 999999]));
check('an unknown reading source is refused', $refused(['reading_source' => 'guessed']));
check('an unknown status is refused', $refused(['status' => 'overdue']));
check('a period already billed on that meter is refused', $refused([
    'period_start' => date('Y-m-15', strtotime($monthStart(2))),
    'period_end'   => date('Y-m-14', strtotime($monthStart(1))),
]));

// ---- the tenant's latest period ---------------------------------------------
$latest = $issue([
    'period_start'     => $monthStart(1),
    'period_end'       => $monthEnd(1),
    'previous_reading' => 1290.5,
    'current_reading'  => 1400.0,
    'due_on'           => $inDays(20),
]);
check('a period that overlaps nothing is accepted', (float) $latest['units_kwh'] === 109.5);

// ---- one tenant never sees another's usage ----------------------------------
ElectricBill::issue([
    'meter_id'         => $meterId,
    'lead_id'          => $neighbour,
    'period_start'     => $monthStart(4),
    'period_end'       => $monthEnd(4),
    'previous_reading' => 900,
    'current_reading'  => 1000,
]);

$mine = ElectricBill::forTenant($tenant);
$theirs = ElectricBill::forTenant($neighbour);
check('a tenant sees only the bills issued to them', count($mine) === 3, (string) count($mine));
check('the previous occupant keeps their own bill on the same meter', count($theirs) === 1);
check('bills come back newest period first', $mine[0]['period_start'] === $monthStart(1), $mine[0]['period_start']);
check('each bill carries the meter and room it belongs to',
    $mine[0]['meter_serial'] === 'TEST-MTR-01' && $mine[0]['room_name'] === 'Meter Test Room');

// ---- the portal summary -----------------------------------------------------
$summary = ElectricBill::summaryForTenant($tenant);
$unpaidTotal = round((float) $latest['amount_rm'] + (float) $open['amount_rm'], 2);

check('outstanding counts only unpaid bills',
    $summary['outstanding_count'] === 2 && $summary['outstanding_rm'] === $unpaidTotal,
    json_encode([$summary['outstanding_count'], $summary['outstanding_rm'], $unpaidTotal]));
check('the next due date is the earliest unpaid one', $summary['next_due_on'] === $inDays(6), (string) $summary['next_due_on']);
check('the average is taken across every billed period',
    $summary['average_kwh'] === round((109.5 + 162.0 + 128.5) / 3, 1), json_encode($summary['average_kwh']));
check('the change is the latest period against the one before',
    $summary['change_kwh'] === round(109.5 - 162.0, 2), json_encode($summary['change_kwh']));

// ---- overdue is derived, never stored ---------------------------------------
$pastDue = $issue([
    'period_start'     => $monthStart(5),
    'period_end'       => $monthEnd(5),
    'previous_reading' => 800,
    'current_reading'  => 890,
    'due_on'           => date('Y-m-d', strtotime('-1 day')),
]);
check('an unpaid bill past its due date reads as overdue', ElectricBill::isOverdue($pastDue));
check('overdue is not a stored status', $pastDue['status'] === 'unpaid');
check('a paid bill is never overdue', ElectricBill::isOverdue($paid) === false);
check('a bill still within its term is not overdue', ElectricBill::isOverdue($open) === false);
check('the summary counts the overdue bill', ElectricBill::summaryForTenant($tenant)['overdue_count'] === 1);

// ---- chart series ------------------------------------------------------------
$series = ElectricBill::usageSeries(ElectricBill::forTenant($tenant), 3);
check('the usage series is oldest-first for the chart',
    count($series) === 3 && $series[0]['period_start'] < $series[2]['period_start'],
    json_encode(array_column($series, 'period_start')));

// ---- leave the inventory as this file found it -------------------------------
// Removing the rooms cascades their meters, and the meters cascade the bills.
Room::delete($otherRoomId);
Room::delete($roomId);
Lead::delete($tenant);
Lead::delete($neighbour);
check('teardown leaves no meter behind', ElectricBill::meterForRoom($roomId) === null);
check('teardown leaves no bill behind', ElectricBill::forTenant($tenant) === []);
