<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Database;

require dirname(__DIR__) . '/_portal_layout.php';

if (!empty($_SESSION['owner_name'])) {
    header('Location: /owner/dashboard');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['owner_name'] ?? '');
    $code = $_POST['access_code'] ?? '';
    $expected = $_ENV['OWNER_PORTAL_CODE'] ?? '';

    $ownsRooms = $name !== '' && Database::run(
        'SELECT COUNT(*) FROM rooms WHERE owner_name = ?',
        [$name]
    )->fetchColumn() > 0;

    if ($expected !== '' && hash_equals($expected, $code) && $ownsRooms) {
        session_regenerate_id(true);
        $_SESSION['owner_name'] = $name;
        header('Location: /owner/dashboard');
        exit;
    }
    $error = $ownsRooms || $name === ''
        ? 'Wrong access code.'
        : 'No listings found under that owner name.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#F5833C">
<title>Owner portal · beLive</title>
<link rel="icon" type="image/png" href="/assets/img/belive-favicon.png">
<link rel="stylesheet" href="/assets/css/belive-theme.css">
<link rel="stylesheet" href="/assets/css/portal.css">
</head>
<body class="portal-owner portal-login">
<div class="belive-card portal-login-card">
    <div style="text-align:center; margin-bottom:16px">
        <img src="/assets/img/belive-logo.png" alt="beLive" style="height:38px; display:inline-block">
        <h1 style="font-size:19px; margin-top:8px">Higher returns. Zero stress.</h1>
        <p class="belive-muted" style="font-size:13.5px; margin-top:4px">More income, less hassle — manage your listings in one place.</p>
    </div>

    <?php if ($error !== null): ?><div class="belive-alert danger"><?= e($error) ?></div><?php endif; ?>

    <form method="post" action="/owner/login">
        <div class="belive-field">
            <label for="owner_name">Owner name</label>
            <input id="owner_name" name="owner_name" type="text" required autofocus placeholder="As registered on your listings">
        </div>
        <div class="belive-field">
            <label for="access_code">Access code</label>
            <input id="access_code" name="access_code" type="password" required>
            <div class="hint">Issued by BeLive admin (competition scope: shared code from .env).</div>
        </div>
        <button type="submit" class="belive-btn-primary" style="width:100%; justify-content:center">Open owner portal</button>
    </form>
</div>
</body>
</html>
