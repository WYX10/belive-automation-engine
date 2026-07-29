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
$live = Lead::hottest(10);
check('the live list never returns more than the ten asked for', count($live) === min(10, $total), (string) count($live));

/** Where a given lead sits in the ranking, or null if it missed the list. */
$rank = static function (array $list, int $id): ?int {
    foreach ($list as $index => $row) {
        if ((int) $row['id'] === $id) {
            return $index;
        }
    }

    return null;
};

// The panel is badged "top 10", so the ranking has to be closing probability,
// not chatter: a lead Eve just spoke to must not outrank a hotter quiet one.
Database::run(
    'UPDATE leads SET closing_probability = 100, last_contact_at = ? WHERE id = ?',
    [date('Y-m-d H:i:s', time() - 86400), $boundaryId]
);
Database::run(
    'UPDATE leads SET closing_probability = 40, last_contact_at = ? WHERE id = ?',
    [date('Y-m-d H:i:s', time() + 60), $thisMonthId]
);
// Rank the whole table for the ordering checks: the ten-row cut is asserted
// above, and earlier tests may have left more than ten scored leads behind.
$live = Lead::hottest(500);
check('the hotter lead outranks the more recently active one',
    $rank($live, $boundaryId) < $rank($live, $thisMonthId),
    json_encode([$rank($live, $boundaryId), $rank($live, $thisMonthId)]));

$ordered = true;
foreach ($live as $index => $row) {
    if ($index > 0 && (int) $row['closing_probability'] > (int) $live[$index - 1]['closing_probability']) {
        $ordered = false;
    }
}
check('the list stays in highest-probability-first order', $ordered);

// An unscored lead is unknown, not promising — it belongs below every scored one.
Database::run(
    'UPDATE leads SET closing_probability = NULL, last_contact_at = ? WHERE id = ?',
    [date('Y-m-d H:i:s', time() + 120), $lastMonthId]
);
$live = Lead::hottest(500);
$firstUnscored = null;
$scoredAfterUnscored = false;
foreach ($live as $index => $row) {
    if ($row['closing_probability'] === null) {
        $firstUnscored ??= $index;
    } elseif ($firstUnscored !== null) {
        $scoredAfterUnscored = true;
    }
}
check('an unscored lead never outranks a scored one, however recent', !$scoredAfterUnscored,
    json_encode(array_column($live, 'closing_probability')));

// Equal odds: the live conversation is the one worth opening first.
Database::run(
    'UPDATE leads SET closing_probability = 40, last_contact_at = ? WHERE id = ?',
    [date('Y-m-d H:i:s', time() + 120), $lastMonthId]
);
$live = Lead::hottest(500);
check('on equal probability the more recently active lead ranks higher',
    $rank($live, $lastMonthId) < $rank($live, $thisMonthId),
    json_encode([$rank($live, $lastMonthId), $rank($live, $thisMonthId)]));

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
