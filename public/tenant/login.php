<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Database;

require dirname(__DIR__) . '/_portal_layout.php';

if (!empty($_SESSION['tenant_lead_id'])) {
    header('Location: /tenant/dashboard');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = preg_replace('/[^0-9]/', '', $_POST['wa_phone'] ?? '');

    // Competition-scope access: the WhatsApp number that holds a booking IS
    // the identity (that number already received the booking confirmation).
    $lead = Database::run(
        'SELECT l.* FROM leads l WHERE l.wa_phone = ?
           AND EXISTS (SELECT 1 FROM bookings b WHERE b.lead_id = l.id)
         LIMIT 1',
        [$phone]
    )->fetch();

    if ($lead !== false) {
        session_regenerate_id(true);
        $_SESSION['tenant_lead_id'] = (int) $lead['id'];
        header('Location: /tenant/dashboard');
        exit;
    }
    $error = 'No booking found for that WhatsApp number. Book a viewing with Eve first!';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#F5833C">
<title>Tenant portal · beLive</title>
<link rel="stylesheet" href="/assets/css/belive-theme.css">
<link rel="stylesheet" href="/assets/css/portal.css">
</head>
<body class="portal-tenant portal-login">
<div class="belive-card portal-login-card">
    <div style="text-align:center; margin-bottom:16px">
        <div class="belive-wordmark" style="font-size:30px"><span class="be">be</span><span class="live">Live</span></div>
        <h1 style="font-size:19px; margin-top:8px">The smarter way to rent</h1>
        <p class="belive-muted" style="font-size:13.5px; margin-top:4px">Your stay, your agreement, your peace of mind — all in one place.</p>
    </div>

    <?php if ($error !== null): ?><div class="belive-alert danger"><?= e($error) ?></div><?php endif; ?>

    <form method="post" action="/tenant/login">
        <div class="belive-field">
            <label for="wa_phone">Your WhatsApp number</label>
            <input id="wa_phone" name="wa_phone" type="tel" placeholder="60123456789" required autofocus>
            <div class="hint">The number you booked your viewing with.</div>
        </div>
        <button type="submit" class="belive-btn-secondary" style="width:100%; justify-content:center">Open my portal</button>
    </form>

    <ul class="belive-check-list" style="margin-top:16px">
        <li>Verified listing card</li>
        <li>Move-in condition log</li>
        <li>Digital agreement</li>
        <li>Fair pricing guard</li>
    </ul>
</div>
</body>
</html>
