<?php

declare(strict_types=1);

/**
 * Seeds the viewing-staff roster and a handful of demo viewings so every card
 * on /admin/staff has something to show — the roster grid, the 7-day coverage
 * heatmap, upcoming leave, assigned viewings, and the "no agent yet" queue.
 *
 *   php database/seed_staff_schedule.php
 *
 * Unlike database/seed.php (which only runs on a fresh database), this is
 * IDEMPOTENT and safe to run against an already-populated DB — local or the
 * live Azure one — because the demo roster starts empty in production and the
 * main seed will not re-run there.
 *
 * It owns only its own demo rows, identified by fixed markers:
 *   · staff        — the four wa_phone numbers 6017000030{1..4}
 *   · time off     — cascades from those staff
 *   · shifts       — cascades from those staff
 *   · bookings     — notes prefixed with the BOOKING_TAG below
 *   · demo leads   — only the ones this script created (same tag in notes)
 * On each run those rows are removed and rebuilt, so re-running gives a clean,
 * known state and never duplicates or touches real bookings/leads.
 *
 * The four agents are deliberately different so the page shows real contrast:
 *   Aida    — video + in person, weekday days   (all-rounder)
 *   Daniel  — video only,        weekday evenings(remote host)
 *   Rajesh  — in person only,    Tue/Thu/Sat    (site specialist)
 *   Mei Ling— video + in person, weekends        (weekend cover)
 * Their definitions mirror database/seeds/demo_data.sql — keep the two in step.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(APP_ROOT)->safeLoad();
date_default_timezone_set('Asia/Kuala_Lumpur');

const BOOKING_TAG = '[staff-demo]';

$cfg = require APP_ROOT . '/config/database.php';
$pdo = \App\Core\Database::pdo();

// Weekday numbering matches PHP date('w') and the app: 0 = Sunday … 6 = Saturday.
$agents = [
    'Aida Zulkifli' => [
        'role' => 'Senior viewing agent', 'wa_phone' => '60170000301', 'email' => 'aida@belive.asia',
        'handles_video' => 1, 'handles_in_person' => 1, 'max_daily_viewings' => 6,
        'shifts' => [[1, '10:00', '18:00'], [2, '10:00', '18:00'], [3, '10:00', '18:00'], [4, '10:00', '18:00'], [5, '10:00', '18:00']],
    ],
    'Daniel Chong' => [
        'role' => 'Video host', 'wa_phone' => '60170000302', 'email' => 'daniel@belive.asia',
        'handles_video' => 1, 'handles_in_person' => 0, 'max_daily_viewings' => 10,
        'shifts' => [[1, '14:00', '21:00'], [2, '14:00', '21:00'], [3, '14:00', '21:00'], [4, '14:00', '21:00'], [5, '14:00', '21:00']],
    ],
    'Rajesh Kumar' => [
        'role' => 'In-person specialist', 'wa_phone' => '60170000303', 'email' => 'rajesh@belive.asia',
        'handles_video' => 0, 'handles_in_person' => 1, 'max_daily_viewings' => 4,
        'shifts' => [[2, '10:00', '16:00'], [4, '10:00', '16:00'], [6, '10:00', '16:00']],
    ],
    'Mei Ling Tan' => [
        'role' => 'Weekend agent', 'wa_phone' => '60170000304', 'email' => 'meiling@belive.asia',
        'handles_video' => 1, 'handles_in_person' => 1, 'max_daily_viewings' => 5,
        'shifts' => [[0, '10:00', '17:00'], [6, '10:00', '17:00']],
    ],
];

$phones = array_column($agents, 'wa_phone');
$placeholders = implode(',', array_fill(0, count($phones), '?'));

$pdo->beginTransaction();

try {
    // ---- clear this script's previous demo rows (idempotent) ---------------
    // Bookings first — they reference staff_id (no FK cascade on that column).
    $pdo->prepare("DELETE FROM bookings WHERE notes LIKE ?")->execute([BOOKING_TAG . '%']);
    // Staff removal cascades to staff_shifts and staff_time_off (FK ON DELETE).
    $pdo->prepare("DELETE FROM staff WHERE wa_phone IN ($placeholders)")->execute($phones);
    // Demo leads this script created on a previous run (only ours — tagged).
    $pdo->prepare("DELETE FROM leads WHERE notes LIKE ?")->execute([BOOKING_TAG . '%']);

    // ---- staff + shifts ----------------------------------------------------
    $insStaff = $pdo->prepare(
        'INSERT INTO staff (name, role, wa_phone, email, handles_video, handles_in_person, max_daily_viewings)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $insShift = $pdo->prepare(
        'INSERT INTO staff_shifts (staff_id, weekday, starts_at, ends_at) VALUES (?, ?, ?, ?)'
    );
    $staffId = [];
    foreach ($agents as $name => $a) {
        $insStaff->execute([$name, $a['role'], $a['wa_phone'], $a['email'], $a['handles_video'], $a['handles_in_person'], $a['max_daily_viewings']]);
        $id = (int) $pdo->lastInsertId();
        $staffId[$name] = $id;
        foreach ($a['shifts'] as [$weekday, $from, $to]) {
            $insShift->execute([$id, $weekday, $from . ':00', $to . ':00']);
        }
    }

    /** Date (Y-m-d) of the next day on/after tomorrow whose weekday is in $set. */
    $nextWeekday = static function (array $set) : string {
        for ($d = 1; $d <= 8; $d++) {
            $ts = strtotime("+$d day");
            if (in_array((int) date('w', $ts), $set, true)) {
                return date('Y-m-d', $ts);
            }
        }
        return date('Y-m-d', strtotime('+1 day')); // unreachable for the sets used
    };

    // ---- upcoming leave — a real hole in the coverage grid -----------------
    // Anchored to each agent's *next working day* so the leave always lands on
    // a day they would otherwise be rostered — that is what carves the hole.
    $insOff = $pdo->prepare(
        'INSERT INTO staff_time_off (staff_id, starts_at, ends_at, reason) VALUES (?, ?, ?, ?)'
    );
    $aidaLeave = $nextWeekday([1, 2, 3, 4, 5]);          // full weekday off
    $danielLeave = $nextWeekday([1, 2, 3, 4, 5]);        // one weekday evening off
    $insOff->execute([$staffId['Aida Zulkifli'], "$aidaLeave 00:00:00", "$aidaLeave 23:59:00", 'Annual leave']);
    $insOff->execute([$staffId['Daniel Chong'], "$danielLeave 18:00:00", "$danielLeave 21:00:00", 'Family dinner']);

    // ---- demo leads to attach viewings to ----------------------------------
    // Always this script's OWN tagged leads — never borrow real ones, so on the
    // live database no fake viewing is ever pinned to a real customer. Removed
    // and rebuilt on each run (the DELETE above matches the same tag).
    $demoLeads = [
        ['Farah Idris',  '60181110001', 'Cheras'],
        ['Wei Jie Lim',  '60181110002', 'Sentul'],
        ['Nurul Huda',   '60181110003', 'Setapak'],
        ['Arjun Menon',  '60181110004', 'Kuala Lumpur'],
    ];
    $insLead = $pdo->prepare(
        "INSERT INTO leads (wa_phone, name, source_channel, status, location, notes)
         VALUES (?, ?, 'whatsapp', 'qualified', ?, ?)"
    );
    $leadIds = [];
    foreach ($demoLeads as [$ln, $lp, $loc]) {
        $insLead->execute([$lp, $ln, $loc, BOOKING_TAG . ' demo viewing lead']);
        $leadIds[] = (int) $pdo->lastInsertId();
    }

    /**
     * Next date on/after tomorrow whose weekday is in $weekdays and is not the
     * given agent's leave day, returned at $hour:00. Guarantees a slot that is
     * in the future and inside the next 7 days for any weekday set used here.
     */
    $nextSlot = static function (array $weekdays, int $hour, array $skipDates = []) : string {
        for ($d = 1; $d <= 8; $d++) {
            $ts = strtotime("+$d day");
            if (in_array((int) date('w', $ts), $weekdays, true)
                && !in_array(date('Y-m-d', $ts), $skipDates, true)) {
                return date('Y-m-d ', $ts) . sprintf('%02d:00:00', $hour);
            }
        }
        return date('Y-m-d 15:00:00', strtotime('+1 day')); // unreachable for these sets
    };

    // ---- assigned viewings (populate the roster + subtract from coverage) ---
    $insBooking = $pdo->prepare(
        'INSERT INTO bookings (lead_id, room_id, viewing_datetime, viewing_mode, staff_id, status, confirmation_sent, notes)
         VALUES (?, NULL, ?, ?, ?, ?, ?, ?)'
    );

    $plan = [
        // [lead index, agent, weekdays the agent works, hour, mode, status, confirmed?]
        [0, 'Aida Zulkifli', [1, 2, 3, 4, 5], 11, 'in_person',  'confirmed', 1],
        [1, 'Daniel Chong',  [1, 2, 3, 4, 5], 15, 'video_call', 'confirmed', 1],
        [2, 'Rajesh Kumar',  [2, 4, 6],       11, 'in_person',  'pending',   0],
        [3, 'Mei Ling Tan',  [0, 6],          14, 'video_call', 'confirmed', 1],
    ];
    $assigned = 0;
    foreach ($plan as [$idx, $name, $weekdays, $hour, $mode, $status, $sent]) {
        $slot = $nextSlot($weekdays, $hour, $name === 'Aida Zulkifli' ? [$aidaLeave] : []);
        $insBooking->execute([$leadIds[$idx], $slot, $mode, $staffId[$name], $status, $sent, BOOKING_TAG . " assigned to $name"]);
        $assigned++;
    }

    // ---- one unassigned viewing → lights the "no agent" queue --------------
    // A staffable weekday 11:00 in-person slot (Aida/Rajesh could take it), but
    // left unassigned so the manual-assignment dropdown has candidates to show.
    $unassignedSlot = $nextSlot([2, 4], 11, [$aidaLeave]); // Tue/Thu, when Rajesh also works
    $insBooking->execute([$leadIds[0], $unassignedSlot, 'in_person', null, 'pending', 0, BOOKING_TAG . ' awaiting manual assignment']);

    $pdo->commit();

    echo "Staff schedule seeded:\n";
    echo '  agents:   ' . count($staffId) . "\n";
    echo '  shifts:   ' . (int) $pdo->query('SELECT COUNT(*) FROM staff_shifts')->fetchColumn() . "\n";
    echo '  time off: ' . (int) $pdo->query('SELECT COUNT(*) FROM staff_time_off')->fetchColumn() . "\n";
    echo "  viewings: $assigned assigned + 1 unassigned\n";
    echo "\nOpen /admin/staff to see the roster, coverage grid and assignments.\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Seed failed (rolled back): ' . $e->getMessage() . "\n");
    exit(1);
}
