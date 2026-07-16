<?php

declare(strict_types=1);

/**
 * Inbound Meta webhook receiver (POST). Two event families arrive here:
 *  - object=whatsapp_business_account → WhatsApp messages → conversation pipeline
 *  - object=page / instagram          → FB/IG comments   → lead capture (Phase 6)
 *
 * Always answers 200 quickly: Meta retries non-200 deliveries aggressively,
 * and a processing bug must not cause webhook deregistration mid-demo.
 */

defined('APP_BOOTED') || exit('No direct access.');

use App\Integrations\Meta\CommentWebhookParser;
use App\Integrations\WhatsApp\WebhookParser;
use App\Pipeline\Conversion\ConversationManager;
use App\Pipeline\LeadGeneration\CommentScanner;

$payload = json_decode(file_get_contents('php://input') ?: '', true);

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['received' => true]);

// Flush the 200 to Meta before doing any real work.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    flush();
}

if (!is_array($payload)) {
    return;
}

try {
    $object = $payload['object'] ?? '';

    if ($object === 'whatsapp_business_account') {
        if (WebhookParser::isStatusOnly($payload)) {
            return; // delivery/read receipts — nothing to do
        }
        $manager = new ConversationManager();
        foreach (WebhookParser::parseInboundMessages($payload) as $message) {
            $manager->handleInbound($message);
        }
        return;
    }

    if ($object === 'page' || $object === 'instagram') {
        foreach (CommentWebhookParser::parse($payload) as $comment) {
            CommentScanner::capture($comment);
        }
    }
} catch (\Throwable $e) {
    // Log and swallow — the 200 already went out, and one bad payload must
    // never take the webhook down.
    error_log('[webhook/whatsapp] ' . $e->getMessage());
    try {
        \App\Core\Database::run(
            'INSERT INTO ai_activity_log (action, detail) VALUES (?, ?)',
            ['webhook_error', mb_substr($e->getMessage(), 0, 800)]
        );
    } catch (\Throwable) {
        // Database down too — error_log above is the last resort.
    }
}
