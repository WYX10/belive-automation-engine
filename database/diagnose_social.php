<?php

declare(strict_types=1);

/**
 * Why did that comment not get a reply?
 *
 *   php database/diagnose_social.php
 *
 * Read-only. Answers, in the order the chain actually breaks:
 * is the schema there, is the feature on, is a credential live, did the
 * webhook arrive, and what happened to it. Written for a live host where the
 * admin panel is one more thing to log into.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(APP_ROOT)->safeLoad();
require APP_ROOT . '/config/constants.php';

$cfg = require APP_ROOT . '/config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset']),
    $cfg['user'],
    $cfg['pass'],
    $cfg['options']
);

$line = static fn (string $s = '') => print($s . "\n");
$ok = static fn (bool $good) => $good ? '  OK  ' : ' FAIL ';

$line();
$line('=== 1. Schema ===');
$tables = ['social_replies', 'app_settings', 'leads', 'api_credentials'];
$missing = [];
foreach ($tables as $table) {
    $exists = (bool) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table'"
    )->fetchColumn();
    $line($ok($exists) . $table);
    if (!$exists) {
        $missing[] = $table;
    }
}
if ($missing !== []) {
    $line();
    $line('>>> Run: php database/migrate.php');
    $line('>>> Without social_replies the webhook throws, the error is swallowed,');
    $line('>>> and Meta still gets its 200 — so nothing anywhere looks wrong.');
    exit(1);
}

$applied = $pdo->query("SELECT filename FROM migrations ORDER BY filename DESC LIMIT 4")->fetchAll(PDO::FETCH_COLUMN);
$line('  last migrations applied: ' . implode(', ', $applied));

$line();
$line('=== 2. Is the feature switched on? ===');
$settings = $pdo->query(
    "SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE 'social%'"
)->fetchAll(PDO::FETCH_KEY_PAIR);
if ($settings === []) {
    $line(' FAIL no social_* settings — migration 036 did not finish');
} else {
    $line($ok(($settings['social_autoreply_enabled'] ?? '0') === '1') . 'auto-reply enabled');
    $line($ok(($settings['social_wa_number'] ?? '') !== '') . 'WhatsApp number set: ' . ($settings['social_wa_number'] ?: '(empty — link has no token)'));
    $scope = $settings['social_reply_scope'] ?? 'enquiry';
    $line('  reply scope: ' . $scope
        . ($scope === 'enquiry' ? '   (only comments that read like a rental enquiry get a DM)' : ''));
}

$line();
$line('=== 3. Credentials ===');
foreach ($pdo->query("SELECT service, label, is_active, test_status FROM api_credentials ORDER BY service")->fetchAll() as $row) {
    $line(($row['is_active'] ? '  ON  ' : '  off ') . str_pad($row['service'], 12)
        . str_pad((string) $row['test_status'], 8) . $row['label']);
}
$line('  (a reply cannot be delivered unless meta_graph — or instagram — is ON)');

$line();
$line('=== 4. Did the webhook arrive? ===');
$events = $pdo->query(
    "SELECT created_at, platform, event_type, sender_name, sender_id, public_reply, private_reply, error
     FROM social_replies ORDER BY id DESC LIMIT 5"
)->fetchAll();
if ($events === []) {
    $line('  NO EVENTS RECORDED — the comment/DM never reached the pipeline.');
} else {
    foreach ($events as $e) {
        $line("  {$e['created_at']}  {$e['platform']}/{$e['event_type']}  from "
            . ($e['sender_name'] ?: $e['sender_id'])
            . "  public={$e['public_reply']} private={$e['private_reply']}");
        if ($e['error']) {
            $line('        error: ' . mb_substr($e['error'], 0, 240));
        }
    }
}

$line();
$line('=== 5. What the engine logged (most recent first) ===');
$log = $pdo->query(
    "SELECT created_at, action, detail FROM ai_activity_log
     WHERE action IN ('social_comment_skipped','social_reply_skipped','webhook_error','social_dry_run_reply','skill_automate','social_lead_merged','social_lead_attributed')
     ORDER BY id DESC LIMIT 12"
)->fetchAll();
if ($log === []) {
    $line('  nothing logged — no webhook has been processed at all.');
    $line();
    $line('>>> Meta is not delivering. Check, in this order:');
    $line('>>>  - Page: Edit Page Subscriptions has feed + messages ticked');
    $line('>>>  - POST /{page-id}/subscribed_apps returned success');
    $line('>>>  - Instagram: the per-account Webhook subscription toggle is On');
    $line('>>>  - the app is in published state');
} else {
    foreach ($log as $l) {
        $line("  {$l['created_at']}  " . str_pad($l['action'], 24) . mb_substr((string) $l['detail'], 0, 150));
    }
}

$line();
