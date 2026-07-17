<?php

declare(strict_types=1);

/**
 * Shared tenant/owner portal layout. Tenant pages take the tone of BeLive's
 * tenant panel ("The Smarter Way to Rent"); owner pages the owner panel
 * ("Higher Returns. Zero Stress. Smarter Rentals.").
 */

defined('APP_BOOTED') || exit('No direct access.');

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('set_flash')) {
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
}

function eve_whatsapp_link(): string
{
    $number = preg_replace('/[^0-9]/', '', $_ENV['EVE_WA_NUMBER'] ?? '');

    return $number !== '' ? "https://wa.me/$number" : 'https://wa.link/hg32ho';
}

function portal_header(string $audience, string $title, string $active = ''): void
{
    $isTenant = $audience === 'tenant';
    $nav = $isTenant
        ? [
            'dashboard'    => ['/tenant/dashboard', 'My stay'],
            'rewards'      => ['/tenant/rewards', 'Rent rewards'],
            'verification' => ['/tenant/listing_verification', 'Verified listing'],
            'move_in'      => ['/tenant/move_in_log', 'Move-in log'],
            'agreement'    => ['/tenant/agreement', 'My agreement'],
            'pricing'      => ['/tenant/fair_pricing', 'Fair pricing'],
        ]
        : [
            'dashboard'  => ['/owner/dashboard', 'Overview'],
            'properties' => ['/owner/properties', 'Properties & rooms'],
            'listings'   => ['/owner/listings', 'Listing tools'],
            'agreements' => ['/owner/agreements', 'Agreements'],
            'pricing'    => ['/owner/pricing_guard', 'Pricing guard'],
        ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#F5833C">
<title><?= e($title) ?> · beLive</title>
<link rel="icon" type="image/png" href="/assets/img/belive-favicon.png">
<link rel="stylesheet" href="/assets/css/belive-theme.css">
<link rel="stylesheet" href="/assets/css/portal.css">
</head>
<body class="portal-<?= e($audience) ?>">
<nav class="portal-nav">
    <a href="/" style="text-decoration:none"><img src="/assets/img/belive-logo.svg" alt="beLive" style="height:28px; display:block"></a>
    <div class="links">
        <?php foreach ($nav as $key => [$href, $label]): ?>
            <a class="nav-link <?= $key === $active ? 'active' : '' ?>" href="<?= e($href) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
    <a class="belive-btn-secondary" style="padding:7px 14px; font-size:13px" href="<?= e(eve_whatsapp_link()) ?>" target="_blank" rel="noopener">💬 Chat with Eve</a>
    <a class="belive-btn-ghost" style="padding:7px 14px; font-size:13px" href="/<?= e($audience) ?>/logout">Log out</a>
</nav>
<main class="portal-main">
    <?php foreach (take_flashes() as $flash): ?>
        <div class="belive-alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>
    <?php
}

function portal_footer(): void
{
    ?>
</main>
</body>
</html>
    <?php
}

/** Tenant session guard — returns the logged-in tenant's lead row. */
function require_tenant(): array
{
    if (empty($_SESSION['tenant_lead_id'])) {
        header('Location: /tenant/login');
        exit;
    }
    $lead = App\Models\Lead::find((int) $_SESSION['tenant_lead_id']);
    if ($lead === null) {
        unset($_SESSION['tenant_lead_id']);
        header('Location: /tenant/login');
        exit;
    }

    return $lead;
}

/** Owner session guard — returns the logged-in owner's name. */
function require_owner(): string
{
    if (empty($_SESSION['owner_name'])) {
        header('Location: /owner/login');
        exit;
    }

    return (string) $_SESSION['owner_name'];
}

/** The room a tenant's portal centres on: their latest booked room. */
function tenant_room(array $lead): ?array
{
    $booking = App\Core\Database::run(
        'SELECT * FROM bookings WHERE lead_id = ? AND room_id IS NOT NULL ORDER BY id DESC LIMIT 1',
        [(int) $lead['id']]
    )->fetch();

    return $booking ? App\Models\Room::find((int) $booking['room_id']) : null;
}
