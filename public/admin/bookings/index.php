<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Auth;
use App\Core\Database;
use App\Models\Booking;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();

// Manual status controls (cancel / complete) for the admin.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    $id = (int) ($_POST['id'] ?? 0);
    $to = $_POST['to'] ?? '';
    if (Booking::find($id) !== null && in_array($to, BOOKING_STATUSES, true)) {
        Booking::setStatus($id, $to);
        set_flash('success', "Booking #$id → $to.");
    }
    header('Location: /admin/bookings');
    exit;
}

$bookings = Database::run(
    'SELECT b.*, l.name AS lead_name, l.wa_phone, r.name AS room_name, r.location AS area
     FROM bookings b
     JOIN leads l ON l.id = b.lead_id
     LEFT JOIN rooms r ON r.id = b.room_id
     ORDER BY b.viewing_datetime DESC LIMIT 200'
)->fetchAll();

$statusTone = ['confirmed' => '', 'pending' => 'orange', 'cancelled' => 'danger', 'completed' => 'muted'];

admin_header('Bookings', 'bookings');
?>
<div class="belive-page-head">
    <h1>Bookings</h1>
    <span class="belive-muted" style="font-size:13px">Created zero-touch by Eve from live conversations.</span>
</div>

<div class="belive-card">
    <?php if ($bookings === []): ?>
        <p class="belive-muted">No bookings yet. When a customer proposes a viewing time on WhatsApp,
        Eve parses it, cross-checks the schedule, books it and sends the confirmation — it shows up here.</p>
    <?php else: ?>
        <table class="belive-table">
            <thead><tr><th>Viewing</th><th>Customer</th><th>Room</th><th>Status</th><th>Confirmation</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($bookings as $booking): ?>
                <tr>
                    <td style="white-space:nowrap">
                        <strong><?= e(date('D, j M Y', strtotime($booking['viewing_datetime']))) ?></strong>
                        <div class="belive-muted" style="font-size:12.5px"><?= e(date('g:ia', strtotime($booking['viewing_datetime']))) ?></div>
                    </td>
                    <td>
                        <a href="/admin/leads/view?id=<?= (int) $booking['lead_id'] ?>"><?= e($booking['lead_name'] ?: $booking['wa_phone']) ?></a>
                        <div class="belive-muted" style="font-size:12px"><?= e($booking['wa_phone']) ?></div>
                    </td>
                    <td style="font-size:13.5px"><?= e($booking['room_name'] ? "{$booking['room_name']} — {$booking['area']}" : 'General viewing') ?></td>
                    <td><span class="belive-badge <?= $statusTone[$booking['status']] ?? '' ?>"><?= e($booking['status']) ?></span></td>
                    <td style="font-size:13px"><?= (int) $booking['confirmation_sent'] === 1 ? '✅ sent' : '<span class="belive-muted">—</span>' ?></td>
                    <td style="white-space:nowrap">
                        <?php if (in_array($booking['status'], ['pending', 'confirmed'], true)): ?>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                                <input type="hidden" name="id" value="<?= (int) $booking['id'] ?>">
                                <input type="hidden" name="to" value="completed">
                                <button class="belive-btn-ghost" style="padding:4px 10px; font-size:12.5px">Complete</button>
                            </form>
                            <form method="post" style="display:inline" data-confirm="Cancel this viewing?">
                                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                                <input type="hidden" name="id" value="<?= (int) $booking['id'] ?>">
                                <input type="hidden" name="to" value="cancelled">
                                <button class="belive-btn-ghost" style="padding:4px 10px; font-size:12.5px; color:var(--belive-danger)">Cancel</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php admin_footer();
