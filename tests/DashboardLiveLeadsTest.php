<?php

declare(strict_types=1);

/**
 * What the admin dashboard reads: monthly lead capture (a single day swings
 * too hard to steer on) and the ten most recently active leads, each of which
 * the dashboard pairs with its own transcript.
 */

use App\Core\Database;
use App\Models\Interaction;
use App\Models\Lead;

$monthStart = date('Y-m-01');
$before = Lead::capturedThisMonth();

$thisMonthId = Lead::create([
    'wa_phone'       => '601155500001',
    'name'           => 'Captured This Month',
    'source_channel' => 'whatsapp',
]);
check('a lead created today counts toward the month', Lead::capturedThisMonth() === $before + 1);

// Backdate one lead to last month and one to the first second of this month:
// the boundary is where a "monthly" counter is usually wrong.
$lastMonthId = Lead::create([
    'wa_phone'       => '601155500002',
    'name'           => 'Captured Last Month',
    'source_channel' => 'website',
]);
Database::run(
    'UPDATE leads SET created_at = ? WHERE id = ?',
    [date('Y-m-d H:i:s', strtotime("$monthStart -1 second")), $lastMonthId]
);
check('a lead from last month does not', Lead::capturedThisMonth() === $before + 1);

$boundaryId = Lead::create([
    'wa_phone'       => '601155500003',
    'name'           => 'Captured On The First',
    'source_channel' => 'referral',
]);
Database::run('UPDATE leads SET created_at = ? WHERE id = ?', ["$monthStart 00:00:00", $boundaryId]);
check('a lead from the first instant of the month does', Lead::capturedThisMonth() === $before + 2);

// ---- live lead list --------------------------------------------------------
$total = (int) Database::run('SELECT COUNT(*) FROM leads')->fetchColumn();
$live = Lead::mostRecentlyActive(10);
check('the live list never returns more than the ten asked for', count($live) === min(10, $total), (string) count($live));

Database::run('UPDATE leads SET last_contact_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() + 60), $thisMonthId]);
$live = Lead::mostRecentlyActive(10);
check('the most recently active lead leads the list', (int) $live[0]['id'] === $thisMonthId, json_encode($live[0]['name'] ?? null));

$ordered = true;
foreach ($live as $index => $row) {
    if ($index > 0 && (string) $row['last_contact_at'] > (string) $live[$index - 1]['last_contact_at']) {
        $ordered = false;
    }
}
check('the list stays in most-recent-first order', $ordered);

// ---- each row's transcript -------------------------------------------------
Interaction::create([
    'lead_id'    => $thisMonthId,
    'phase'      => 'conversion',
    'skill'      => 'create',
    'direction'  => 'inbound',
    'message_in' => 'Is the Setapak room still open?',
    'model_used' => 'mock-offline-stub',
]);
Interaction::create([
    'lead_id'     => $thisMonthId,
    'phase'       => 'conversion',
    'skill'       => 'create',
    'direction'   => 'outbound',
    'message_out' => 'Yes — RM 470/mo on a 12-month stay, zero deposit.',
    'model_used'  => 'mock-offline-stub',
]);

$thread = Interaction::transcript($thisMonthId, 6);
check('the dashboard can pull a per-lead transcript', count($thread) === 2, (string) count($thread));
check('...oldest first, so it reads like a conversation',
    $thread[0]['direction'] === 'inbound' && $thread[1]['direction'] === 'outbound');
check('a lead with no messages yields an empty thread, not an error',
    Interaction::transcript($boundaryId, 6) === []);
