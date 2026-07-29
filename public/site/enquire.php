<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

/**
 * Room enquiry POST handler (channel #2). Creates the lead with room + tenure
 * context, then gets the conversation onto WhatsApp.
 *
 * For a first-time visitor that means handing them a prefilled wa.me link
 * rather than pushing a message at them: WhatsApp's 24-hour window is shut
 * until they message us, so a push would never arrive (WebsiteFormHandler
 * explains the two paths). The link rides in the session — it is long, and a
 * room URL carrying the visitor's own enquiry text is not something to leave
 * in browser history or a referrer header.
 */

use App\Pipeline\LeadGeneration\WebsiteFormHandler;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /rooms');
    exit;
}

$roomId = (int) ($_POST['room_id'] ?? 0);

$result = WebsiteFormHandler::handle([
    'name'     => $_POST['name'] ?? '',
    'wa_phone' => $_POST['wa_phone'] ?? '',
    'message'  => $_POST['message'] ?? '',
    'room_id'  => $roomId,
    'tenure'   => $_POST['tenure'] ?? '',
    'ref_code' => $_POST['ref_code'] ?? '',
]);

if ($result['ok']) {
    $waLink = $result['wa_link'] ?? '';

    // No room page to return to — send them straight to WhatsApp.
    if ($waLink !== '' && $roomId <= 0) {
        header('Location: ' . $waLink);
        exit;
    }
    if ($waLink !== '') {
        $_SESSION['enquiry_wa_link'] = $waLink;
    }

    header('Location: ' . ($roomId > 0 ? "/rooms/$roomId?enquired=1" : '/rooms?enquired=1'));
    exit;
}

// Validation failure: bounce back with the error readable.
header('Location: ' . ($roomId > 0 ? "/rooms/$roomId?error=" . urlencode($result['error']) : '/rooms'));
exit;
