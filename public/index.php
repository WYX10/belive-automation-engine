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
if (!in_array($path, ['/webhook/whatsapp', '/webhook/verify'], true)) {
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

// --- Root → admin ------------------------------------------------------------
$router->get('/', function () {
    header('Location: /admin/dashboard');
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

$router->get('/admin/models', "$pages/admin/models/index.php");
$router->post('/admin/models/switch', "$pages/admin/models/switch.php");

$router->get('/admin/leads', "$pages/admin/leads/index.php");
$router->get('/admin/leads/view', "$pages/admin/leads/view.php");
$router->any('/admin/leads/add', "$pages/admin/leads/add.php");

$router->get('/admin/chat_history', "$pages/admin/chat_history/index.php");
$router->post('/admin/chat_history/flag', "$pages/admin/chat_history/flag_response.php");

$router->get('/admin/learning_log', "$pages/admin/learning_log/index.php");
$router->get('/admin/learning_log/rule', "$pages/admin/learning_log/rule_detail.php");

$router->get('/admin/activity_log', "$pages/admin/activity_log/index.php");

$router->get('/admin/content', "$pages/admin/content/index.php");
$router->any('/admin/content/preview', "$pages/admin/content/preview.php");

$router->get('/admin/bookings', "$pages/admin/bookings/index.php");

// --- Webhooks (Meta calls GET for verification, POST for events) ---------------
$router->get('/webhook/whatsapp', "$pages/webhook/verify.php");
$router->post('/webhook/whatsapp', "$pages/webhook/whatsapp.php");
$router->get('/webhook/verify', "$pages/webhook/verify.php");
$router->any('/webhook/tiktok_fallback', "$pages/webhook/tiktok_fallback.php");

// --- Public lead-capture endpoints ----------------------------------------------
$router->any('/enquiry', "$pages/enquiry.php");           // website smart enquiry form
$router->get('/r/{code}', function () {                    // Refer & Earn share link
    App\Pipeline\Referral\ReferralLinkGenerator::handleVisit($_GET['code'] ?? '');
});

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
