<?php

declare(strict_types=1);

/**
 * Meta webhook verification handshake. When you register the callback URL in
 * the Meta App dashboard, Meta sends GET ?hub.mode=subscribe&hub.verify_token=
 * ...&hub.challenge=...; we must echo the challenge iff the token matches
 * WA_VERIFY_TOKEN from .env.
 */

defined('APP_BOOTED') || exit('No direct access.');

$mode = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
$token = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
$challenge = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';

$expected = $_ENV['WA_VERIFY_TOKEN'] ?? '';

if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo $challenge;
    exit;
}

http_response_code(403);
echo 'Verification failed.';
