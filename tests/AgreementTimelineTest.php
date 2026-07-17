<?php

declare(strict_types=1);

/**
 * Proves agreement dates are calculated, stored, and converted into each
 * tenant-facing countdown state without relying on the server's current date.
 */

use App\Agreements\DigitalAgreementGenerator;
use App\Core\Database;
use App\Models\DigitalAgreement;
use App\Models\Lead;
use App\Models\Room;

$active = DigitalAgreement::timeline(
    ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31'],
    new DateTimeImmutable('2026-11-16')
);
check('active agreement shows exact days left', $active['state'] === 'active' && $active['days_remaining'] === 45);

$endingSoon = DigitalAgreement::timeline(
    ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31'],
    new DateTimeImmutable('2026-12-21')
);
check('agreement enters ending-soon state within 30 days', $endingSoon['state'] === 'ending_soon'
    && $endingSoon['days_remaining'] === 10);

$exactlyThirtyDays = DigitalAgreement::timeline(
    ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31'],
    new DateTimeImmutable('2026-12-01')
);
check('ending-soon threshold includes exactly 30 days', $exactlyThirtyDays['state'] === 'ending_soon'
    && $exactlyThirtyDays['days_remaining'] === 30);

$endingToday = DigitalAgreement::timeline(
    ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31'],
    new DateTimeImmutable('2026-12-31')
);
check('agreement ending today is explicit', $endingToday['state'] === 'ending_today');

$expired = DigitalAgreement::timeline(
    ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31'],
    new DateTimeImmutable('2027-01-03')
);
check('expired agreement shows days since expiry', $expired['state'] === 'expired' && $expired['days_since_end'] === 3);

$upcoming = DigitalAgreement::timeline(
    ['starts_on' => '2026-08-10', 'ends_on' => '2027-08-09'],
    new DateTimeImmutable('2026-08-05')
);
check('future agreement shows days until start', $upcoming['state'] === 'upcoming' && $upcoming['days_until_start'] === 5);
check('invalid agreement dates have no misleading countdown', DigitalAgreement::timeline([
    'starts_on' => 'not-a-date',
    'ends_on' => '2027-08-09',
]) === null);
check('undated legacy agreements have no inferred countdown', DigitalAgreement::timeline([
    'starts_on' => null,
    'ends_on' => null,
]) === null);
check('reversed agreement dates have no countdown', DigitalAgreement::timeline([
    'starts_on' => '2027-08-09',
    'ends_on' => '2026-08-10',
]) === null);

$leadId = Lead::create([
    'wa_phone' => '601199999901',
    'name' => 'Agreement Timeline Tenant',
    'source_channel' => 'whatsapp',
    'preferred_tenure' => '6_month',
]);
$roomId = Room::create([
    'name' => 'Agreement Timeline Room',
    'location' => 'Setapak',
    'room_type' => 'middle',
    'owner_name' => 'Agreement Timeline Owner',
    'address' => '1 Timeline Street, Setapak',
]);
Database::run(
    'INSERT INTO room_pricing (room_id, tenure, price, is_best_value) VALUES (?, ?, ?, ?), (?, ?, ?, ?), (?, ?, ?, ?)',
    [$roomId, '6_month', 800, 1, $roomId, 'monthly', 900, 0, $roomId, '12_month', 750, 1]
);

$generated = DigitalAgreementGenerator::generate($leadId, $roomId, '2026-08-01');
check('generator stores the tenant tenure', $generated['tenure'] === '6_month');
check('generator stores the chosen start date', $generated['starts_on'] === '2026-08-01');
check('generator calculates an inclusive six-month end date', $generated['ends_on'] === '2027-01-31');
check('generated agreement text includes its term dates', str_contains($generated['agreement_text'], '2026-08-01')
    && str_contains($generated['agreement_text'], '2027-01-31'));

Lead::update($leadId, ['preferred_tenure' => 'monthly']);
$monthEnd = DigitalAgreementGenerator::generate($leadId, $roomId, '2026-01-31');
check('month-end starts run through the target month end without overflowing', $monthEnd['ends_on'] === '2026-02-28');

Lead::update($leadId, ['preferred_tenure' => '12_month']);
$leapDay = DigitalAgreementGenerator::generate($leadId, $roomId, '2024-02-29');
check('leap-day annual terms end on the following February month-end', $leapDay['ends_on'] === '2025-02-28');
