<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Auth;
use App\Core\Database;
use App\Models\Booking;
use App\Models\Staff;
use App\Pipeline\Booking\StaffScheduler;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    try {
        switch ((string) ($_POST['do'] ?? '')) {
            case 'add_staff':
                $name = trim((string) ($_POST['name'] ?? ''));
                if ($name === '') {
                    throw new InvalidArgumentException('Give the staff member a name.');
                }
                Staff::create([
                    'name'               => $name,
                    'role'               => trim((string) ($_POST['role'] ?? '')) ?: 'Viewing agent',
                    'wa_phone'           => trim((string) ($_POST['wa_phone'] ?? '')) ?: null,
                    'email'              => trim((string) ($_POST['email'] ?? '')) ?: null,
                    'handles_video'      => isset($_POST['handles_video']) ? 1 : 0,
                    'handles_in_person'  => isset($_POST['handles_in_person']) ? 1 : 0,
                    'max_daily_viewings' => max(1, min(24, (int) ($_POST['max_daily_viewings'] ?? 8))),
                ]);
                set_flash('success', "$name added to the team. Give them a weekly shift so Eve can book them.");
                break;

            case 'update_staff':
                $id = (int) ($_POST['id'] ?? 0);
                if (Staff::find($id) === null) {
                    throw new InvalidArgumentException('Unknown staff member.');
                }
                Staff::update($id, [
                    'name'               => trim((string) ($_POST['name'] ?? '')) ?: 'Unnamed',
                    'role'               => trim((string) ($_POST['role'] ?? '')) ?: 'Viewing agent',
                    'wa_phone'           => trim((string) ($_POST['wa_phone'] ?? '')) ?: null,
                    'email'              => trim((string) ($_POST['email'] ?? '')) ?: null,
                    'handles_video'      => isset($_POST['handles_video']) ? 1 : 0,
                    'handles_in_person'  => isset($_POST['handles_in_person']) ? 1 : 0,
                    'max_daily_viewings' => max(1, min(24, (int) ($_POST['max_daily_viewings'] ?? 8))),
                    'active'             => isset($_POST['active']) ? 1 : 0,
                ]);
                set_flash('success', 'Staff details saved.');
                break;

            case 'add_shift':
                $staffId = (int) ($_POST['staff_id'] ?? 0);
                $weekdays = array_map('intval', (array) ($_POST['weekday'] ?? []));
                if ($weekdays === []) {
                    throw new InvalidArgumentException('Pick at least one day for the shift.');
                }
                foreach ($weekdays as $weekday) {
                    Staff::addShift(
                        $staffId,
                        $weekday,
                        (string) ($_POST['starts_at'] ?? ''),
                        (string) ($_POST['ends_at'] ?? '')
                    );
                }
                set_flash('success', 'Shift added to the weekly roster — Eve can book those hours now.');
                break;

            case 'remove_shift':
                Staff::removeShift((int) ($_POST['shift_id'] ?? 0));
                set_flash('success', 'Shift removed.');
                break;

            case 'add_time_off':
                Staff::addTimeOff(
                    (int) ($_POST['staff_id'] ?? 0),
                    (string) ($_POST['starts_at'] ?? ''),
                    (string) ($_POST['ends_at'] ?? ''),
                    trim((string) ($_POST['reason'] ?? ''))
                );
                set_flash('success', 'Time off recorded — those hours are now off-limits to Eve.');
                break;

            case 'remove_time_off':
                Staff::removeTimeOff((int) ($_POST['time_off_id'] ?? 0));
                set_flash('success', 'Time off removed.');
                break;

            case 'assign_booking':
                $bookingId = (int) ($_POST['booking_id'] ?? 0);
                $staffId = (int) ($_POST['staff_id'] ?? 0);
                if (Booking::find($bookingId) === null || Staff::find($staffId) === null) {
                    throw new InvalidArgumentException('Unknown booking or staff member.');
                }
                Booking::update($bookingId, ['staff_id' => $staffId]);
                set_flash('success', "Booking #$bookingId assigned.");
                break;

            default:
                throw new InvalidArgumentException('Choose a valid staff action.');
        }
    } catch (InvalidArgumentException $e) {
        set_flash('danger', $e->getMessage());
    } catch (\PDOException $e) {
        error_log('[admin staff database] ' . $e->getMessage());
        set_flash('danger', 'That change could not be saved. Please check the details and try again.');
    } catch (\Throwable $e) {
        error_log('[admin staff] ' . $e->getMessage());
        set_flash('danger', 'That change could not be saved. Please try again.');
    }

    header('Location: /admin/staff');
    exit;
}

$team = Staff::all([], 'active DESC, name ASC');
$activeTeam = array_values(array_filter($team, fn ($s) => (int) $s['active'] === 1));
$shiftsByStaff = [];
foreach (Staff::shifts() as $shift) {
    $shiftsByStaff[(int) $shift['staff_id']][(int) $shift['weekday']][] = $shift;
}

$timeOff = Staff::upcomingTimeOff();
$assignments = Staff::upcomingAssignments(7);
$coverage = $activeTeam !== [] ? StaffScheduler::coverageGrid(7) : [];

$unassigned = Database::run(
    "SELECT b.id, b.viewing_datetime, b.viewing_mode, b.status, l.name AS lead_name, l.wa_phone,
            r.name AS room_name
       FROM bookings b
       JOIN leads l ON l.id = b.lead_id
       LEFT JOIN rooms r ON r.id = b.room_id
      WHERE b.staff_id IS NULL AND b.status IN ('pending','confirmed') AND b.viewing_datetime >= NOW()
      ORDER BY b.viewing_datetime"
)->fetchAll();

// Weekly rostered hours, for the "how much cover do we have?" stat.
$activeIds = array_map(fn ($s) => (int) $s['id'], $activeTeam);
$rosteredHours = 0.0;
foreach ($shiftsByStaff as $staffId => $byWeekday) {
    if (!in_array($staffId, $activeIds, true)) {
        continue;
    }
    foreach ($byWeekday as $shifts) {
        foreach ($shifts as $shift) {
            $rosteredHours += (strtotime($shift['ends_at']) - strtotime($shift['starts_at'])) / 3600;
        }
    }
}

$modeLabel = static fn (?string $mode): string => match ($mode) {
    'video_call' => '💻 Video call',
    'in_person'  => '🤝 In person',
    default      => 'not picked yet',
};

admin_header('Staff schedule', 'staff');
?>
<div class="belive-page-head">
    <h1>Staff schedule</h1>
    <span class="belive-muted" style="font-size:13px">
        Eve only offers a viewing slot when someone here is rostered to take it.
    </span>
</div>

<?php if ($activeTeam === []): ?>
    <div class="belive-alert warning">
        No active staff yet — Eve is booking slots without checking human availability.
        Add your first agent below and give them a weekly shift to switch scheduling on.
    </div>
<?php endif; ?>

<div class="belive-stat-grid" style="margin-bottom:20px">
    <div class="belive-stat">
        <div class="belive-stat-icon">👤</div>
        <div class="belive-stat-number"><?= count($activeTeam) ?></div>
        <div class="belive-stat-label">Active agents</div>
    </div>
    <div class="belive-stat">
        <div class="belive-stat-icon">🗓</div>
        <div class="belive-stat-number"><?= (int) round($rosteredHours) ?></div>
        <div class="belive-stat-label">Rostered hours / week</div>
    </div>
    <div class="belive-stat">
        <div class="belive-stat-icon">📅</div>
        <div class="belive-stat-number"><?= count($assignments) ?></div>
        <div class="belive-stat-label">Viewings assigned (7 days)</div>
    </div>
    <div class="belive-stat">
        <div class="belive-stat-icon"><?= $unassigned === [] ? '✅' : '⚠️' ?></div>
        <div class="belive-stat-number"><?= count($unassigned) ?></div>
        <div class="belive-stat-label">Unassigned viewings</div>
    </div>
</div>

<?php if ($coverage !== []): ?>
<div class="belive-card">
    <div class="belive-card-title">🔭 Next 7 days — who is free to host</div>
    <p class="belive-muted" style="font-size:13px; margin-bottom:12px">
        Each cell is the number of agents still free for that hour, after shifts, leave and
        booked viewings. A grey cell is an hour Eve will not offer to a customer.
    </p>
    <div style="overflow-x:auto">
        <table class="belive-table" style="font-size:12.5px">
            <thead>
                <tr>
                    <th style="text-align:left">Day</th>
                    <?php foreach (array_keys(reset($coverage)['hours']) as $hour): ?>
                        <th style="text-align:center; font-weight:600"><?= sprintf('%02d', $hour) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($coverage as $day): ?>
                <tr>
                    <td style="white-space:nowrap; font-weight:600"><?= e($day['label']) ?></td>
                    <?php foreach ($day['hours'] as $hour => $cell): ?>
                        <?php
                        $free = (int) $cell['free'];
                        $style = $free === 0
                            ? 'background:rgba(107,114,128,0.10); color:var(--belive-muted)'
                            : ($free === 1
                                ? 'background:var(--belive-orange-soft); color:var(--belive-orange-dark)'
                                : 'background:var(--belive-teal-soft); color:var(--belive-teal-dark)');
                        $title = sprintf('%s %02d:00 — %d of %d rostered agents free', $day['label'], $hour, $free, (int) $cell['rostered']);
                        ?>
                        <td style="text-align:center; font-weight:600; <?= $style ?>" title="<?= e($title) ?>">
                            <?= $free === 0 ? '·' : $free ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="belive-card">
    <div class="belive-card-title">📋 Weekly roster</div>
    <?php if ($team === []): ?>
        <p class="belive-muted">No staff yet — add someone below.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
        <table class="belive-table" style="font-size:13px">
            <thead>
                <tr>
                    <th style="text-align:left">Agent</th>
                    <?php foreach (Staff::WEEKDAYS as $label): ?>
                        <th style="text-align:left"><?= e(substr($label, 0, 3)) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($team as $member): ?>
                <?php $memberId = (int) $member['id']; ?>
                <tr style="<?= (int) $member['active'] === 1 ? '' : 'opacity:.5' ?>">
                    <td style="white-space:nowrap">
                        <strong><?= e($member['name']) ?></strong>
                        <div class="belive-muted" style="font-size:12px"><?= e($member['role']) ?></div>
                        <div style="font-size:11.5px; margin-top:3px">
                            <?= (int) $member['handles_video'] === 1 ? '💻' : '' ?>
                            <?= (int) $member['handles_in_person'] === 1 ? '🤝' : '' ?>
                            <span class="belive-muted">max <?= (int) $member['max_daily_viewings'] ?>/day</span>
                        </div>
                    </td>
                    <?php for ($weekday = 0; $weekday < 7; $weekday++): ?>
                        <td style="vertical-align:top">
                            <?php foreach ($shiftsByStaff[$memberId][$weekday] ?? [] as $shift): ?>
                                <form method="post" style="display:block; margin-bottom:3px"
                                      data-confirm="Remove this shift?">
                                    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                                    <input type="hidden" name="do" value="remove_shift">
                                    <input type="hidden" name="shift_id" value="<?= (int) $shift['id'] ?>">
                                    <span class="belive-badge" style="font-size:11.5px">
                                        <?= e(substr($shift['starts_at'], 0, 5)) ?>–<?= e(substr($shift['ends_at'], 0, 5)) ?>
                                    </span>
                                    <button class="belive-btn-ghost"
                                            style="padding:0 5px; font-size:12px; color:var(--belive-danger)"
                                            title="Remove shift">✕</button>
                                </form>
                            <?php endforeach; ?>
                            <?php if (($shiftsByStaff[$memberId][$weekday] ?? []) === []): ?>
                                <span class="belive-muted" style="font-size:12px">—</span>
                            <?php endif; ?>
                        </td>
                    <?php endfor; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <form method="post" style="margin-top:18px; border-top:1px solid var(--belive-line); padding-top:16px">
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
        <input type="hidden" name="do" value="add_shift">
        <div class="belive-row">
            <div class="belive-col belive-field" style="max-width:230px">
                <label for="shift_staff">Agent</label>
                <select id="shift_staff" name="staff_id" required>
                    <?php foreach ($team as $member): ?>
                        <option value="<?= (int) $member['id'] ?>"><?= e($member['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="belive-col belive-field" style="max-width:130px">
                <label for="shift_start">From</label>
                <input id="shift_start" type="time" name="starts_at" value="10:00" required>
            </div>
            <div class="belive-col belive-field" style="max-width:130px">
                <label for="shift_end">To</label>
                <input id="shift_end" type="time" name="ends_at" value="19:00" required>
            </div>
            <div class="belive-col belive-field">
                <label>Days</label>
                <div style="display:flex; gap:10px; flex-wrap:wrap; padding-top:4px">
                    <?php foreach (Staff::WEEKDAYS as $weekday => $label): ?>
                        <label style="font-weight:500; display:flex; align-items:center; gap:5px">
                            <input type="checkbox" name="weekday[]" value="<?= $weekday ?>"
                                   style="width:auto" <?= $weekday >= 1 && $weekday <= 5 ? 'checked' : '' ?>>
                            <?= e(substr($label, 0, 3)) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <button class="belive-btn-primary">Add shift</button>
    </form>
    <?php endif; ?>
</div>

<div class="belive-row">
    <div class="belive-col">
        <div class="belive-card">
            <div class="belive-card-title">🌴 Time off</div>
            <?php if ($timeOff === []): ?>
                <p class="belive-muted" style="font-size:13.5px">No upcoming leave. Anything added here is
                carved out of the roster immediately.</p>
            <?php else: ?>
                <table class="belive-table" style="font-size:13px">
                    <tbody>
                    <?php foreach ($timeOff as $off): ?>
                        <tr>
                            <td>
                                <strong><?= e($off['staff_name']) ?></strong>
                                <div class="belive-muted" style="font-size:12.5px">
                                    <?= e(date('j M, g:ia', strtotime($off['starts_at']))) ?>
                                    → <?= e(date('j M, g:ia', strtotime($off['ends_at']))) ?>
                                    <?= $off['reason'] !== null ? ' · ' . e($off['reason']) : '' ?>
                                </div>
                            </td>
                            <td style="text-align:right">
                                <form method="post" data-confirm="Remove this time off?">
                                    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                                    <input type="hidden" name="do" value="remove_time_off">
                                    <input type="hidden" name="time_off_id" value="<?= (int) $off['id'] ?>">
                                    <button class="belive-btn-ghost"
                                            style="padding:2px 8px; font-size:12px; color:var(--belive-danger)">✕</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ($team !== []): ?>
            <form method="post" style="margin-top:14px; border-top:1px solid var(--belive-line); padding-top:14px">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <input type="hidden" name="do" value="add_time_off">
                <div class="belive-field">
                    <label for="off_staff">Agent</label>
                    <select id="off_staff" name="staff_id" required>
                        <?php foreach ($team as $member): ?>
                            <option value="<?= (int) $member['id'] ?>"><?= e($member['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="belive-row">
                    <div class="belive-col belive-field">
                        <label for="off_start">From</label>
                        <input id="off_start" type="datetime-local" name="starts_at" required>
                    </div>
                    <div class="belive-col belive-field">
                        <label for="off_end">To</label>
                        <input id="off_end" type="datetime-local" name="ends_at" required>
                    </div>
                </div>
                <div class="belive-field">
                    <label for="off_reason">Reason <span class="belive-muted">(optional)</span></label>
                    <input id="off_reason" type="text" name="reason" maxlength="120" placeholder="Annual leave">
                </div>
                <button class="belive-btn-primary">Add time off</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="belive-col">
        <div class="belive-card">
            <div class="belive-card-title">📅 Assigned viewings — next 7 days</div>
            <?php if ($assignments === []): ?>
                <p class="belive-muted" style="font-size:13.5px">Nothing scheduled yet. As Eve books viewings
                she picks the least-loaded agent who is on shift and can handle the chosen mode.</p>
            <?php else: ?>
                <table class="belive-table" style="font-size:13px">
                    <thead><tr><th>When</th><th>Agent</th><th>Customer</th><th>Mode</th></tr></thead>
                    <tbody>
                    <?php foreach ($assignments as $row): ?>
                        <tr>
                            <td style="white-space:nowrap">
                                <?= e(date('D j M', strtotime($row['viewing_datetime']))) ?>
                                <div class="belive-muted" style="font-size:12px"><?= e(date('g:ia', strtotime($row['viewing_datetime']))) ?></div>
                            </td>
                            <td><?= e($row['staff_name']) ?></td>
                            <td>
                                <?= e($row['lead_name'] ?: $row['wa_phone']) ?>
                                <div class="belive-muted" style="font-size:12px"><?= e($row['room_name'] ?: 'General viewing') ?></div>
                            </td>
                            <td style="white-space:nowrap"><?= e($modeLabel($row['viewing_mode'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($unassigned !== []): ?>
<div class="belive-card">
    <div class="belive-card-title">⚠️ Viewings with no agent</div>
    <p class="belive-muted" style="font-size:13px; margin-bottom:12px">
        Booked when nobody was rostered (or before the roster existed). Assign someone by hand —
        agents already busy or off duty at that hour are left out of the list.
    </p>
    <table class="belive-table" style="font-size:13px">
        <thead><tr><th>When</th><th>Customer</th><th>Mode</th><th>Assign to</th></tr></thead>
        <tbody>
        <?php foreach ($unassigned as $row): ?>
            <?php $candidates = StaffScheduler::availableAt($row['viewing_datetime'], $row['viewing_mode'] ?: 'any'); ?>
            <tr>
                <td style="white-space:nowrap">
                    <?= e(date('D j M', strtotime($row['viewing_datetime']))) ?>
                    <div class="belive-muted" style="font-size:12px"><?= e(date('g:ia', strtotime($row['viewing_datetime']))) ?></div>
                </td>
                <td>
                    <a href="/admin/bookings"><?= e($row['lead_name'] ?: $row['wa_phone']) ?></a>
                    <div class="belive-muted" style="font-size:12px"><?= e($row['room_name'] ?: 'General viewing') ?></div>
                </td>
                <td style="white-space:nowrap"><?= e($modeLabel($row['viewing_mode'])) ?></td>
                <td>
                    <?php if ($candidates === []): ?>
                        <span class="belive-muted" style="font-size:12.5px">Nobody free — extend a shift or move the viewing.</span>
                    <?php else: ?>
                        <form method="post" style="display:flex; gap:8px; align-items:center">
                            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                            <input type="hidden" name="do" value="assign_booking">
                            <input type="hidden" name="booking_id" value="<?= (int) $row['id'] ?>">
                            <select name="staff_id" style="padding:5px 8px; font-size:12.5px; border-radius:8px; border:1.5px solid var(--belive-line)">
                                <?php foreach ($candidates as $candidate): ?>
                                    <option value="<?= (int) $candidate['id'] ?>"><?= e($candidate['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="belive-btn-ghost" style="padding:4px 10px; font-size:12.5px">Assign</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="belive-card">
    <div class="belive-card-title">👥 The team</div>
    <?php foreach ($team as $member): ?>
        <form method="post" style="border-bottom:1px solid var(--belive-line); padding-bottom:14px; margin-bottom:14px">
            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
            <input type="hidden" name="do" value="update_staff">
            <input type="hidden" name="id" value="<?= (int) $member['id'] ?>">
            <div class="belive-row">
                <div class="belive-col belive-field">
                    <label>Name</label>
                    <input type="text" name="name" value="<?= e($member['name']) ?>" required>
                </div>
                <div class="belive-col belive-field">
                    <label>Role</label>
                    <input type="text" name="role" value="<?= e($member['role']) ?>">
                </div>
                <div class="belive-col belive-field">
                    <label>WhatsApp</label>
                    <input type="text" name="wa_phone" value="<?= e($member['wa_phone']) ?>" placeholder="60123456789">
                </div>
                <div class="belive-col belive-field">
                    <label>Email</label>
                    <input type="email" name="email" value="<?= e($member['email']) ?>">
                </div>
                <div class="belive-col belive-field" style="max-width:130px">
                    <label>Max / day</label>
                    <input type="number" name="max_daily_viewings" min="1" max="24" value="<?= (int) $member['max_daily_viewings'] ?>">
                </div>
            </div>
            <div style="display:flex; gap:18px; flex-wrap:wrap; align-items:center">
                <label style="font-size:13px; display:flex; align-items:center; gap:6px">
                    <input type="checkbox" name="handles_video" style="width:auto" <?= (int) $member['handles_video'] === 1 ? 'checked' : '' ?>>
                    💻 Can host video calls
                </label>
                <label style="font-size:13px; display:flex; align-items:center; gap:6px">
                    <input type="checkbox" name="handles_in_person" style="width:auto" <?= (int) $member['handles_in_person'] === 1 ? 'checked' : '' ?>>
                    🤝 Can host in person
                </label>
                <label style="font-size:13px; display:flex; align-items:center; gap:6px">
                    <input type="checkbox" name="active" style="width:auto" <?= (int) $member['active'] === 1 ? 'checked' : '' ?>>
                    On the roster
                </label>
                <button class="belive-btn-ghost" style="padding:5px 14px; font-size:13px">Save</button>
            </div>
        </form>
    <?php endforeach; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
        <input type="hidden" name="do" value="add_staff">
        <div class="belive-row">
            <div class="belive-col belive-field">
                <label for="new_name">Name</label>
                <input id="new_name" type="text" name="name" placeholder="Aisyah Rahman" required>
            </div>
            <div class="belive-col belive-field">
                <label for="new_role">Role</label>
                <input id="new_role" type="text" name="role" placeholder="Viewing agent">
            </div>
            <div class="belive-col belive-field">
                <label for="new_phone">WhatsApp</label>
                <input id="new_phone" type="text" name="wa_phone" placeholder="60123456789">
            </div>
            <div class="belive-col belive-field">
                <label for="new_email">Email</label>
                <input id="new_email" type="email" name="email">
            </div>
            <div class="belive-col belive-field" style="max-width:130px">
                <label for="new_max">Max / day</label>
                <input id="new_max" type="number" name="max_daily_viewings" min="1" max="24" value="8">
            </div>
        </div>
        <div style="display:flex; gap:18px; flex-wrap:wrap; align-items:center">
            <label style="font-size:13px; display:flex; align-items:center; gap:6px">
                <input type="checkbox" name="handles_video" style="width:auto" checked> 💻 Can host video calls
            </label>
            <label style="font-size:13px; display:flex; align-items:center; gap:6px">
                <input type="checkbox" name="handles_in_person" style="width:auto" checked> 🤝 Can host in person
            </label>
            <button class="belive-btn-primary">Add staff member</button>
        </div>
    </form>
</div>
<?php admin_footer();
