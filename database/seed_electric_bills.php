<?php

declare(strict_types=1);

/**
 * Seeds a demo tenant with six months of per-room electricity bills so
 * /tenant/electric has something to show — the usage chart, the bill table
 * with both meter readings, an outstanding balance, and the meter card.
 *
 *   php database/seed_electric_bills.php
 *
 * Like database/seed_staff_schedule.php (and unlike database/seed.php) this is
 * IDEMPOTENT and safe against an already-populated database: it owns only its
 * own rows, identified by fixed markers, and rebuilds them on every run.
 *   · meter    — serial prefixed DEMO_SERIAL_PREFIX (deleting it cascades its bills)
 *   · lead     — wa_phone DEMO_PHONE, notes prefixed DEMO_TAG
 *   · booking  — notes prefixed DEMO_TAG
 * No real tenant is ever handed a fabricated bill, and no real room record is
 * modified — the meter simply attaches to an existing room.
 *
 * The bills are written through App\Models\ElectricBill::issue(), so the demo
 * figures come out of exactly the same arithmetic the portal reads back; the
 * readings below are the only invented input, and they are plain monthly dial
 * readings, not a simulated live feed.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(APP_ROOT)->safeLoad();
require APP_ROOT . '/config/constants.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

use App\Core\Database;
use App\Models\Booking;
use App\Models\ElectricBill;
use App\Models\Lead;

const DEMO_TAG = '[electric-demo]';
const DEMO_PHONE = '60170000401';
const DEMO_SERIAL_PREFIX = 'DEMO-MTR-';

// Rate BeLive-style co-living operators bill submetered rooms at; the meter row
// owns it, and each bill snapshots it at issue time.
const DEMO_TARIFF_RM_PER_KWH = 0.55;

// Six months of monthly dial readings for one single room — a believable
// 120–190 kWh band for one occupant with aircon, hottest months highest.
const DEMO_MONTHLY_KWH = [128.0, 142.0, 165.0, 151.0, 187.0, 173.0];
const DEMO_OPENING_READING = 4210.0;

$pdo = Database::pdo();

// The demo meter attaches to a real room: the Setapak single from the main
// seed when it exists, otherwise whichever room is lowest-numbered.
$room = Database::run(
    "SELECT id, room_code, name FROM rooms ORDER BY (room_code = 'RM-102') DESC, id ASC LIMIT 1"
)->fetch();

if ($room === false) {
    fwrite(STDERR, "No rooms in the database — run `php database/seed.php` first.\n");
    exit(1);
}

$pdo->beginTransaction();

try {
    // ---- clear this script's previous rows (idempotent) --------------------
    // Meters cascade to their bills; leads cascade to bills issued to them.
    Database::run('DELETE FROM electric_meters WHERE meter_serial LIKE ?', [DEMO_SERIAL_PREFIX . '%']);
    Database::run('DELETE FROM bookings WHERE notes LIKE ?', [DEMO_TAG . '%']);
    Database::run('DELETE FROM leads WHERE wa_phone = ? OR notes LIKE ?', [DEMO_PHONE, DEMO_TAG . '%']);

    // ---- the demo tenant ---------------------------------------------------
    // A completed viewing in the past, so the portal can find her room without
    // holding a future slot any real customer could have booked.
    $leadId = Lead::create([
        'wa_phone'        => DEMO_PHONE,
        'name'            => 'Nurin Farhana',
        'source_channel'  => 'whatsapp',
        'status'          => 'converted',
        'location'        => 'Setapak',
        'notes'           => DEMO_TAG . ' demo tenant for the electricity view',
        'last_contact_at' => date('Y-m-d H:i:s', strtotime('-3 day')),
    ]);

    Booking::create([
        'lead_id'           => $leadId,
        'room_id'           => (int) $room['id'],
        'viewing_datetime'  => date('Y-m-d 15:00:00', strtotime('-7 month')),
        'viewing_mode'      => 'in_person',
        'status'            => 'completed',
        'confirmation_sent' => 1,
        'notes'             => DEMO_TAG . ' viewing that led to the tenancy',
    ]);

    // ---- her meter ---------------------------------------------------------
    $meter = ElectricBill::registerMeter(
        (int) $room['id'],
        DEMO_SERIAL_PREFIX . ($room['room_code'] ?: $room['id']),
        DEMO_TARIFF_RM_PER_KWH,
        0.0,
        date('Y-m-d', strtotime('-7 month'))
    );

    // ---- six closed billing periods ----------------------------------------
    // Calendar months, newest ending last month; the latest is still unpaid so
    // the outstanding balance and due date are visible. That last bill is given
    // a due date relative to TODAY, so the demo shows the same ordinary
    // "due soon" state whichever day of the month the seeder is run on.
    $reading = DEMO_OPENING_READING;
    $issued = 0;
    $lastIndex = count(DEMO_MONTHLY_KWH) - 1;

    foreach (DEMO_MONTHLY_KWH as $i => $kwh) {
        $anchor = strtotime(date('Y-m-01') . ' -' . (count(DEMO_MONTHLY_KWH) - $i) . ' month');
        $previous = $reading;
        $reading = round($reading + $kwh, 2);

        $issuedOn = date('Y-m-d', strtotime(date('Y-m-t', $anchor) . ' +2 day'));
        $isLatest = $i === $lastIndex;

        ElectricBill::issue([
            'meter_id'         => (int) $meter['id'],
            'lead_id'          => $leadId,
            'period_start'     => date('Y-m-01', $anchor),
            'period_end'       => date('Y-m-t', $anchor),
            'previous_reading' => $previous,
            'current_reading'  => $reading,
            'reading_source'   => 'smart_meter',
            'issued_on'        => $issuedOn,
            'due_on'           => $isLatest
                ? date('Y-m-d', strtotime('+9 day'))
                : date('Y-m-d', strtotime($issuedOn . ' +' . ElectricBill::PAYMENT_TERM_DAYS . ' day')),
            'status'           => $isLatest ? 'unpaid' : 'paid',
            'paid_at'          => $isLatest ? null : date('Y-m-d H:i:s', strtotime($issuedOn . ' +6 day')),
        ]);
        $issued++;
    }

    $pdo->commit();

    $summary = ElectricBill::summaryForTenant($leadId);

    echo "Electricity demo seeded:\n";
    echo '  room:     ' . $room['name'] . ' (' . ($room['room_code'] ?: 'no code') . ")\n";
    echo '  meter:    ' . $meter['meter_serial'] . ' @ RM ' . number_format(DEMO_TARIFF_RM_PER_KWH, 2) . "/kWh\n";
    echo "  bills:    $issued (" . $summary['outstanding_count'] . ' unpaid, RM '
        . number_format($summary['outstanding_rm'], 2) . " outstanding)\n";
    echo '  average:  ' . number_format((float) $summary['average_kwh'], 1) . " kWh per period\n";
    echo "\nLog in at /tenant/login with " . DEMO_PHONE . " and open /tenant/electric.\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Seed failed (rolled back): ' . $e->getMessage() . "\n");
    exit(1);
}
