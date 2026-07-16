<?php

declare(strict_types=1);

/**
 * Public website layout — the judges' front door. Same brand tokens as every
 * other surface (belive-theme.css first, site.css overrides).
 */

defined('APP_BOOTED') || exit('No direct access.');

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('eve_whatsapp_link')) {
    function eve_whatsapp_link(): string
    {
        $number = preg_replace('/[^0-9]/', '', $_ENV['EVE_WA_NUMBER'] ?? '');

        return $number !== '' ? "https://wa.me/$number" : 'https://wa.link/hg32ho';
    }
}

/** All three tenure prices for a card — a price is never shown unlabelled. */
function tenure_rows(array $prices): void
{
    foreach (App\Models\Room::TENURES as $tenure) {
        if (!isset($prices[$tenure])) {
            continue;
        }
        $best = $prices[$tenure]['is_best_value'];
        ?>
        <div class="tenure-row <?= $best ? 'best' : '' ?>">
            <span><?= e(App\Models\Room::TENURE_LABELS[$tenure]) ?><?= $best ? ' <span class="bv">★ BEST VALUE</span>' : '' ?></span>
            <span class="price">RM <?= e(number_format($prices[$tenure]['price'])) ?><span style="font-size:11px; font-weight:400; color:var(--belive-muted)">/mo</span></span>
        </div>
        <?php
    }
}

function room_card(array $room): void
{
    ?>
    <a class="room-card" href="/rooms/<?= (int) $room['id'] ?>" style="text-decoration:none; color:inherit">
        <div class="photo">
            <?php if ($room['cover_image']): ?>
                <img src="<?= e($room['cover_image']) ?>" alt="<?= e($room['property_name'] ?? $room['name']) ?>" loading="lazy">
            <?php endif; ?>
            <span class="code"><?= e($room['room_code']) ?></span>
            <span class="deposit">RM 0 DEPOSIT</span>
        </div>
        <div class="body">
            <div class="title"><?= e($room['property_name'] ?: $room['name']) ?></div>
            <div class="meta">
                📍 <?= e($room['location']) ?> · <?= e(ucfirst($room['room_type'])) ?> room
                · <span class="belive-badge <?= $room['status'] === 'available' ? '' : 'orange' ?>" style="font-size:10.5px"><?= e($room['status']) ?></span>
            </div>
            <div class="tenure-rows"><?php tenure_rows($room['prices']); ?></div>
        </div>
    </a>
    <?php
}

function site_header(string $title, string $active = ''): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#F5833C">
<meta name="description" content="Fully furnished rooms. Zero deposit. Weekly cleaning. Just bring your bag — we handle the rest.">
<title><?= e($title) ?> · beLive</title>
<link rel="icon" type="image/png" href="/assets/img/belive-favicon.png">
<link rel="stylesheet" href="/assets/css/belive-theme.css">
<link rel="stylesheet" href="/assets/css/site.css">
</head>
<body>
<nav class="site-nav">
    <a href="/" style="text-decoration:none"><img src="/assets/img/belive-logo.png" alt="beLive" style="height:32px; display:block"></a>
    <div class="links">
        <a class="nav-link <?= $active === 'home' ? 'active' : '' ?>" href="/">Home</a>
        <a class="nav-link <?= $active === 'rooms' ? 'active' : '' ?>" href="/rooms">Find a room</a>
        <a class="nav-link" href="/tenant/login">Tenant portal</a>
        <a class="nav-link" href="/owner/login">Owner portal</a>
    </div>
    <a class="belive-btn-primary" style="padding:8px 16px; font-size:13.5px" href="<?= e(eve_whatsapp_link()) ?>" target="_blank" rel="noopener">💬 Chat with us</a>
</nav>
<main class="site-main">
    <?php
}

function site_footer(): void
{
    ?>
</main>
<footer class="site-footer">
    <img src="/assets/img/belive-logo.png" alt="beLive" style="height:22px; display:inline-block; vertical-align:middle">
    &nbsp;·&nbsp; Live Smarter, Stay Better. &nbsp;·&nbsp; Demo build — GrenA · TAR UMT Johor · BeLive × TAR UMT AI Solopreneur Challenge 2026
</footer>
</body>
</html>
    <?php
}
