<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Auth;

require __DIR__ . '/_layout.php';

if (Auth::check()) {
    header('Location: /admin/dashboard');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::attempt(trim($_POST['username'] ?? ''), $_POST['password'] ?? '')) {
        header('Location: /admin/dashboard');
        exit;
    }
    $error = 'Wrong username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#F5833C">
<title>Log in · BeLive Automation Engine</title>
<link rel="icon" type="image/png" href="/assets/img/belive-favicon.png">
<link rel="stylesheet" href="/assets/css/belive-theme.css">
<link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin-login">
<div class="belive-card login-card">
    <div style="text-align:center; margin-bottom:18px">
        <img src="/assets/img/belive-logo.svg" alt="beLive" style="height:40px; display:inline-block">
        <div class="belive-muted" style="font-size:13px; margin-top:4px">Automation Engine — admin panel</div>
    </div>

    <?php if ($error !== null): ?>
        <div class="belive-alert danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/admin/login">
        <div class="belive-field">
            <label for="username">Username</label>
            <input id="username" name="username" type="text" autocomplete="username" required autofocus>
        </div>
        <div class="belive-field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
        </div>
        <button type="submit" class="belive-btn-primary" style="width:100%; justify-content:center">Log in</button>
    </form>
</div>
</body>
</html>
