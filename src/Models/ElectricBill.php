<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use InvalidArgumentException;

/**
 * electric_meters + electric_bills (migration 034) — the per-room electricity
 * submeter behind /tenant/electric. Every BeLive room has its own meter, so a
 * tenant is billed for the units their own room used, not a headcount split of
 * a whole-unit bill.
 *
 * A bill is worked out once, at issue time, and stored complete:
 *
 *     units  = current_reading − previous_reading
 *     amount = units × rate + standing charge
 *
 * The rate and standing charge are snapshotted onto the row, so re-tariffing a
 * meter never rewrites a bill the tenant has already been shown.
 *
 * Reads are scoped to the lead the bill was issued to — never to the room —
 * so a new tenant can never see the previous occupant's consumption.
 */
final class ElectricBill extends BaseModel
{
    protected const TABLE = 'electric_bills';

    /** Default window between issuing a bill and its due date. */
    public const PAYMENT_TERM_DAYS = 14;

    // ---- meters ------------------------------------------------------------

    public static function meterForRoom(int $roomId): ?array
    {
        $row = Database::run(
            'SELECT * FROM electric_meters WHERE room_id = ? LIMIT 1',
            [$roomId]
        )->fetch();

        return $row ?: null;
    }

    /**
     * Register (or re-register) the meter fitted to a room. One meter per room:
     * a replacement unit updates the same row, keeping the bill history intact.
     */
    public static function registerMeter(int $roomId, string $serial, float $tariff, float $standingCharge = 0.0, ?string $installedOn = null): array
    {
        $serial = trim($serial);
        if ($serial === '') {
            throw new InvalidArgumentException('A meter needs a serial number.');
        }
        if ($tariff <= 0) {
            throw new InvalidArgumentException('The tariff must be more than RM 0.00 per kWh.');
        }
        if ($standingCharge < 0) {
            throw new InvalidArgumentException('A standing charge cannot be negative.');
        }
        if (Room::find($roomId) === null) {
            throw new InvalidArgumentException('Unknown room.');
        }

        Database::run(
            'INSERT INTO electric_meters (room_id, meter_serial, tariff_rm_per_kwh, standing_charge_rm, installed_on)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE meter_serial = VALUES(meter_serial),
                                     tariff_rm_per_kwh = VALUES(tariff_rm_per_kwh),
                                     standing_charge_rm = VALUES(standing_charge_rm),
                                     installed_on = VALUES(installed_on)',
            [$roomId, $serial, $tariff, $standingCharge, $installedOn]
        );

        $meter = self::meterForRoom($roomId);
        if ($meter === null) {
            throw new InvalidArgumentException('The meter could not be registered.');
        }

        return $meter;
    }

    // ---- issuing a bill ----------------------------------------------------

    /**
     * Turn a pair of meter readings into a bill for one tenant.
     *
     * Required: meter_id, lead_id, period_start, period_end, previous_reading,
     * current_reading. Optional: reading_source, rate_rm_per_kwh,
     * standing_charge_rm, issued_on, due_on, status, notes.
     *
     * @param array<string, mixed> $input
     * @return array the stored bill row
     */
    public static function issue(array $input): array
    {
        $meter = Database::run(
            'SELECT * FROM electric_meters WHERE id = ? LIMIT 1',
            [(int) ($input['meter_id'] ?? 0)]
        )->fetch();
        if ($meter === false) {
            throw new InvalidArgumentException('Unknown meter.');
        }
        if ((int) $meter['active'] !== 1) {
            throw new InvalidArgumentException('That meter is no longer in service.');
        }

        $leadId = (int) ($input['lead_id'] ?? 0);
        if (Lead::find($leadId) === null) {
            throw new InvalidArgumentException('Unknown tenant.');
        }

        $start = self::date($input['period_start'] ?? '', 'period start');
        $end = self::date($input['period_end'] ?? '', 'period end');
        if ($end <= $start) {
            throw new InvalidArgumentException('A billing period must end after it starts.');
        }

        $previous = self::reading($input['previous_reading'] ?? null, 'previous');
        $current = self::reading($input['current_reading'] ?? null, 'current');
        if ($current < $previous) {
            throw new InvalidArgumentException(
                'The current reading is lower than the previous one — a meter does not run backwards. '
                . 'Register the replacement meter instead.'
            );
        }

        if (self::periodOverlaps((int) $meter['id'], $start, $end)) {
            throw new InvalidArgumentException("This meter already has a bill covering $start to $end.");
        }

        $source = (string) ($input['reading_source'] ?? 'manual');
        if (!in_array($source, ELECTRIC_READING_SOURCES, true)) {
            throw new InvalidArgumentException("Unknown reading source: $source");
        }

        $status = (string) ($input['status'] ?? 'unpaid');
        if (!in_array($status, ELECTRIC_BILL_STATUSES, true)) {
            throw new InvalidArgumentException("Unknown bill status: $status");
        }

        $rate = isset($input['rate_rm_per_kwh'])
            ? (float) $input['rate_rm_per_kwh']
            : (float) $meter['tariff_rm_per_kwh'];
        $standing = isset($input['standing_charge_rm'])
            ? (float) $input['standing_charge_rm']
            : (float) $meter['standing_charge_rm'];
        if ($rate <= 0 || $standing < 0) {
            throw new InvalidArgumentException('The rate must be positive and the standing charge cannot be negative.');
        }

        $units = round($current - $previous, 2);
        $issuedOn = isset($input['issued_on']) ? self::date($input['issued_on'], 'issue date') : $end;
        $dueOn = isset($input['due_on'])
            ? self::date($input['due_on'], 'due date')
            : date('Y-m-d', strtotime($issuedOn . ' +' . self::PAYMENT_TERM_DAYS . ' day'));

        $id = self::create([
            'meter_id'           => (int) $meter['id'],
            'lead_id'            => $leadId,
            'period_start'       => $start,
            'period_end'         => $end,
            'previous_reading'   => $previous,
            'current_reading'    => $current,
            'units_kwh'          => $units,
            'rate_rm_per_kwh'    => $rate,
            'standing_charge_rm' => $standing,
            'amount_rm'          => round($units * $rate + $standing, 2),
            'status'             => $status,
            'reading_source'     => $source,
            'issued_on'          => $issuedOn,
            'due_on'             => $dueOn,
            'paid_at'            => $status === 'paid' ? ($input['paid_at'] ?? date('Y-m-d H:i:s')) : null,
            'notes'              => ($input['notes'] ?? '') !== '' ? $input['notes'] : null,
        ]);

        return self::find($id) ?? throw new InvalidArgumentException('The bill could not be stored.');
    }

    // ---- tenant reads ------------------------------------------------------

    /**
     * Every bill issued to this tenant, newest period first, with the meter and
     * room it belongs to. Scoped by lead_id only — see the class docblock.
     *
     * @return array<int, array>
     */
    public static function forTenant(int $leadId, int $limit = 0): array
    {
        $sql = 'SELECT b.*, m.meter_serial, m.room_id, r.name AS room_name, r.property_name
                FROM electric_bills b
                JOIN electric_meters m ON m.id = b.meter_id
                LEFT JOIN rooms r ON r.id = m.room_id
                WHERE b.lead_id = ?
                ORDER BY b.period_start DESC, b.id DESC';
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }

        return Database::run($sql, [$leadId])->fetchAll();
    }

    /**
     * Everything the portal shows above the bill table: what is owed, the
     * latest period, how it compares with the one before, and the average.
     *
     * @return array{
     *     bills: array<int, array>, latest: ?array, previous: ?array,
     *     outstanding_rm: float, outstanding_count: int, overdue_count: int,
     *     average_kwh: ?float, change_kwh: ?float, next_due_on: ?string
     * }
     */
    public static function summaryForTenant(int $leadId): array
    {
        $bills = self::forTenant($leadId);

        $outstanding = 0.0;
        $outstandingCount = 0;
        $overdue = 0;
        $nextDue = null;
        $units = [];

        foreach ($bills as $bill) {
            $units[] = (float) $bill['units_kwh'];

            if ($bill['status'] !== 'unpaid') {
                continue;
            }
            $outstanding += (float) $bill['amount_rm'];
            $outstandingCount++;
            if (self::isOverdue($bill)) {
                $overdue++;
            }
            // Bills come back newest first, so the last unpaid one seen is the
            // earliest — the date the tenant needs to act on next.
            $nextDue = $bill['due_on'];
        }

        $latest = $bills[0] ?? null;
        $previous = $bills[1] ?? null;

        return [
            'bills'             => $bills,
            'latest'            => $latest,
            'previous'          => $previous,
            'outstanding_rm'    => round($outstanding, 2),
            'outstanding_count' => $outstandingCount,
            'overdue_count'     => $overdue,
            'average_kwh'       => $units === [] ? null : round(array_sum($units) / count($units), 1),
            'change_kwh'        => $latest !== null && $previous !== null
                ? round((float) $latest['units_kwh'] - (float) $previous['units_kwh'], 2)
                : null,
            'next_due_on'       => $nextDue,
        ];
    }

    /**
     * Oldest-first slice for the usage chart.
     *
     * @param array<int, array> $bills newest-first, as returned by forTenant()
     * @return array<int, array>
     */
    public static function usageSeries(array $bills, int $months = 6): array
    {
        return array_reverse(array_slice($bills, 0, $months));
    }

    /** Unpaid and past its due date — derived, never stored. */
    public static function isOverdue(array $bill): bool
    {
        return $bill['status'] === 'unpaid' && $bill['due_on'] < date('Y-m-d');
    }

    // ---- helpers -----------------------------------------------------------

    private static function periodOverlaps(int $meterId, string $start, string $end): bool
    {
        return (int) Database::run(
            'SELECT COUNT(*) FROM electric_bills
              WHERE meter_id = ? AND period_start <= ? AND period_end >= ?',
            [$meterId, $end, $start]
        )->fetchColumn() > 0;
    }

    private static function date(mixed $value, string $label): string
    {
        $ts = is_string($value) || is_int($value) ? strtotime((string) $value) : false;
        if ($ts === false) {
            throw new InvalidArgumentException("Unreadable $label: " . var_export($value, true));
        }

        return date('Y-m-d', $ts);
    }

    private static function reading(mixed $value, string $label): float
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException("The $label reading must be a number.");
        }
        $reading = round((float) $value, 2);
        if ($reading < 0) {
            throw new InvalidArgumentException("The $label reading cannot be negative.");
        }

        return $reading;
    }
}
