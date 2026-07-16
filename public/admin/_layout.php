<?php

declare(strict_types=1);

/**
 * Shared admin layout + view helpers. Every admin page calls
 * admin_header(...) / admin_footer() and escapes output through e().
 */

defined('APP_BOOTED') || exit('No direct access.');

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return $flashes;
}

function admin_header(string $title, string $active = ''): void
{
    $nav = [
        'dashboard'    => ['/admin/dashboard',    '📊', 'Dashboard'],
        'leads'        => ['/admin/leads',        '👥', 'Leads'],
        'chat_history' => ['/admin/chat_history', '💬', 'Chat history'],
        'learning_log' => ['/admin/learning_log', '🧠', 'Learning log'],
        'activity_log' => ['/admin/activity_log', '📜', 'Activity log'],
        'content'      => ['/admin/content',      '📣', 'Content'],
        'bookings'     => ['/admin/bookings',     '📅', 'Bookings'],
        'credentials'  => ['/admin/credentials',  '🔐', 'API credentials'],
        'models'       => ['/admin/models',       '🤖', 'AI models'],
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#F5833C">
<title><?= e($title) ?> · BeLive Automation Engine</title>
<link rel="icon" type="image/png" href="/assets/img/belive-favicon.png">
<link rel="stylesheet" href="/assets/css/belive-theme.css">
<link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin">
<aside class="admin-sidebar">
    <div class="admin-brand">
        <img src="/assets/img/belive-logo.svg" alt="beLive" style="height:28px; display:block">
        <div class="admin-brand-sub">Automation Engine · Eve</div>
    </div>
    <nav class="admin-nav">
        <?php foreach ($nav as $key => [$href, $icon, $label]): ?>
            <a href="<?= e($href) ?>" class="<?= $key === $active ? 'active' : '' ?>">
                <span class="icon"><?= $icon ?></span><?= e($label) ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="admin-sidebar-foot">
        <a href="/admin/logout" class="belive-btn-ghost" style="width:100%; justify-content:center">Log out</a>
    </div>
</aside>
<main class="admin-main">
    <?php foreach (take_flashes() as $flash): ?>
        <div class="belive-alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>
    <?php
}

function admin_footer(): void
{
    ?>
</main>
<script src="/assets/js/admin.js"></script>
</body>
</html>
    <?php
}
