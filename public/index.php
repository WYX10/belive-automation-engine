<?php

declare(strict_types=1);

/**
 * Front controller — every request funnels through here (see .htaccess for
 * Apache; for `php -S localhost:8080 -t public public/index.php` this file
 * doubles as the CLI-server router script).
 *
 * Webhooks route through here too (single entry point, no bypass): Meta's
 * calls are plain HTTP GET/POST, so the front controller adds no friction,
 * and it keeps bootstrap/config handling in exactly one place.
 */

// CLI-server static passthrough: let PHP's built-in server serve real files
// (css/js/img) directly; everything else falls through to the router.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}

define('APP_ROOT', dirname(__DIR__));
define('APP_BOOTED', true);

require APP_ROOT . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(APP_ROOT)->safeLoad();
require APP_ROOT . '/config/constants.php';

date_default_timezone_set('Asia/Kuala_Lumpur');

// Meta's webhook endpoints are cookie-less server-to-server calls; sessions
// are for the human-facing panels (including the manual-intake form, which
// lives under /webhook/tiktok_fallback but is an admin page).
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (!in_array($path, ['/webhook/whatsapp', '/webhook/meta', '/webhook/verify'], true)) {
    session_start();
}

use App\Core\Auth;
use App\Core\Router;

$router = new Router();
$pages = __DIR__;

// --- Health / smoke test ----------------------------------------------------
$router->get('/health', function () {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'app' => 'BeLive Automation Engine', 'time' => date('c')]);
});

// --- Public website (the front door) -------------------------------------------
$router->get('/', "$pages/site/home.php");
$router->get('/rooms', "$pages/site/rooms.php");
$router->get('/rooms/{id}', "$pages/site/room_detail.php");
$router->post('/enquire', "$pages/site/enquire.php");

// Legal pages — public and login-free; developer-platform reviewers (TikTok,
// Meta) open these URLs directly as part of app submission.
$router->get('/terms', function () use ($pages) {
    $_GET['doc'] = 'terms';
    require "$pages/site/legal.php";
});
$router->get('/privacy', function () use ($pages) {
    $_GET['doc'] = 'privacy';
    require "$pages/site/legal.php";
});

// --- Admin panel ---------------------------------------------------------------
$router->any('/admin/login', "$pages/admin/login.php");
$router->get('/admin/logout', function () {
    Auth::logout();
    header('Location: /admin/login');
});
$router->get('/admin/dashboard', "$pages/admin/dashboard.php");

$router->get('/admin/credentials', "$pages/admin/credentials/index.php");
$router->any('/admin/credentials/add', "$pages/admin/credentials/add.php");
$router->post('/admin/credentials/test', "$pages/admin/credentials/test_connection.php");
$router->post('/admin/credentials/delete', "$pages/admin/credentials/delete.php");

$router->get('/admin/models', "$pages/admin/models/index.php");
$router->post('/admin/models/switch', "$pages/admin/models/switch.php");
$router->post('/admin/models/add', "$pages/admin/models/add.php");
$router->post('/admin/models/remove', "$pages/admin/models/remove.php");

$router->get('/admin/leads', "$pages/admin/leads/index.php");
$router->get('/admin/leads/view', "$pages/admin/leads/view.php");
$router->any('/admin/leads/add', "$pages/admin/leads/add.php");

$router->get('/admin/chat_history', "$pages/admin/chat_history/index.php");
$router->post('/admin/chat_history/flag', "$pages/admin/chat_history/flag_response.php");

$router->get('/admin/learning_log', "$pages/admin/learning_log/index.php");
$router->get('/admin/learning_log/rule', "$pages/admin/learning_log/rule_detail.php");

$router->get('/admin/activity_log', "$pages/admin/activity_log/index.php");

$router->any('/admin/social', "$pages/admin/social/index.php");
$router->any('/admin/content', "$pages/admin/content/index.php");
$router->any('/admin/content/preview', "$pages/admin/content/preview.php");
// Engagement accepts POST for its Refresh button; the export streams a CSV.
$router->any('/admin/engagement', "$pages/admin/engagement/index.php");
$router->get('/admin/reports', "$pages/admin/reports/index.php");
$router->get('/admin/reports/export', "$pages/admin/reports/export.php");

$router->get('/admin/bookings', "$pages/admin/bookings/index.php");
$router->any('/admin/agreements', "$pages/admin/agreements/index.php");
$router->any('/admin/agreements/view', "$pages/admin/agreements/view.php");
$router->any('/admin/staff', "$pages/admin/staff/index.php");
$router->any('/admin/property_reviews', "$pages/admin/property_reviews/index.php");
$router->any('/admin/rooms', "$pages/admin/rooms/index.php");
$router->get('/admin/listing_reviews', "$pages/admin/listing_reviews/index.php");
$router->any('/admin/listing_reviews/view', "$pages/admin/listing_reviews/view.php");

// --- Scheduler endpoint (token-gated; for an external cron, no login) ----------
$router->get('/cron/auto_draft', "$pages/cron/auto_draft.php");
$router->post('/cron/content', "$pages/cron/content.php");

// --- Webhooks (Meta calls GET for verification, POST for events) ---------------
$router->get('/webhook/whatsapp', "$pages/webhook/verify.php");
$router->post('/webhook/whatsapp', "$pages/webhook/whatsapp.php");
// Same receiver, second URL: the Messenger/Instagram products are configured
// separately in the Meta App dashboard, and pointing them at /webhook/meta
// reads better there than reusing the WhatsApp path. Both accept all objects.
$router->get('/webhook/meta', "$pages/webhook/verify.php");
$router->post('/webhook/meta', "$pages/webhook/whatsapp.php");
$router->get('/webhook/verify', "$pages/webhook/verify.php");
$router->any('/webhook/tiktok_fallback', "$pages/webhook/tiktok_fallback.php");

// --- Tenant portal (Phase 9 bonus) ----------------------------------------------
$router->any('/tenant/login', "$pages/tenant/login.php");
$router->get('/tenant/logout', function () {
    unset($_SESSION['tenant_lead_id']);
    header('Location: /tenant/login');
});
$router->get('/tenant/dashboard', "$pages/tenant/dashboard.php");
$router->get('/tenant/my_room', "$pages/tenant/my_room.php");
$router->get('/tenant/electric', "$pages/tenant/electric.php");
$router->any('/tenant/rewards', "$pages/tenant/rewards.php");
$router->get('/tenant/listing_verification', "$pages/tenant/listing_verification.php");
$router->get('/tenant/move_in_log', "$pages/tenant/move_in_log.php");
$router->any('/tenant/agreement', "$pages/tenant/agreement.php");
$router->get('/tenant/fair_pricing', "$pages/tenant/fair_pricing.php");

// --- Owner portal (Phase 9 bonus) -------------------------------------------------
$router->any('/owner/login', "$pages/owner/login.php");
$router->get('/owner/logout', function () {
    unset($_SESSION['owner_name']);
    header('Location: /owner/login');
});
$router->get('/owner/dashboard', "$pages/owner/dashboard.php");
$router->any('/owner/properties', "$pages/owner/properties.php");
$router->any('/owner/listings', "$pages/owner/listings/index.php");
$router->any('/owner/listings/verify', "$pages/owner/listings/verify.php");
$router->any('/owner/agreements', "$pages/owner/agreements.php");
$router->any('/owner/tenancies', "$pages/owner/tenancies.php");
$router->get('/owner/pricing_guard', "$pages/owner/pricing_guard.php");

// --- Public lead-capture endpoints ----------------------------------------------
$router->any('/enquiry', "$pages/enquiry.php");           // website smart enquiry form
$router->get('/r/{code}', function () {                    // Refer & Earn share link
    App\Pipeline\Referral\ReferralLinkGenerator::handleVisit($_GET['code'] ?? '');
});

try {
    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
} catch (\Throwable $e) {
    // Never dump a stack trace at a visitor (or a judge): log the real error,
    // show a branded, minimal 500 page.
    error_log('[unhandled] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Something went wrong · beLive</title>'
        . '<link rel="icon" type="image/png" href="/assets/img/belive-favicon.png">'
        . '<link rel="stylesheet" href="/assets/css/belive-theme.css"></head>'
        . '<body style="display:flex;align-items:center;justify-content:center;min-height:100vh">'
        . '<div class="belive-card" style="max-width:420px;text-align:center">'
        . '<img src="/assets/img/belive-logo.svg" alt="beLive" style="height:36px">'
        . '<h1 style="font-size:19px;margin:14px 0 8px">Something went wrong on our side</h1>'
        . '<p class="belive-muted" style="font-size:14px">The team has been notified — please try again in a moment.</p>'
        . '<p style="margin-top:16px"><a class="belive-btn-primary" href="/">Back to home</a></p>'
        . '</div></body></html>';
}
