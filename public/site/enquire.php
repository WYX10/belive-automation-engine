<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

/**
 * Room enquiry POST handler (channel #2). Creates the lead with room + tenure
 * context and triggers Eve's instant WhatsApp follow-up, then returns the
 * visitor to the room page with confirmation.
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
    header('Location: ' . ($roomId > 0 ? "/rooms/$roomId?enquired=1" : '/rooms?enquired=1'));
    exit;
}

// Validation failure: bounce back with the error readable.
header('Location: ' . ($roomId > 0 ? "/rooms/$roomId?error=" . urlencode($result['error']) : '/rooms'));
exit;
