<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

/**
 * The daily draft run over HTTP, for a scheduler that lives outside the app —
 * a GitHub Action, Azure Logic App, or any free uptime pinger. This is what
 * makes the drafts arrive on a day when no admin opens the panel at all.
 *
 *   GET /cron/auto_draft?token=<CRON_TOKEN>          run if the day's run is owed
 *   GET /cron/auto_draft?token=<CRON_TOKEN>&force=1  run now regardless
 *
 * Disabled entirely until CRON_TOKEN is set in .env — an open endpoint that
 * spends AI credits is not something to leave lying around. The token is
 * compared in constant time, and a wrong one gets the same 404 as a missing
 * feature so probing tells an attacker nothing.
 */

use App\Content\AutoDrafter;

header('Content-Type: application/json');

$expected = (string) ($_ENV['CRON_TOKEN'] ?? '');
$given = (string) ($_GET['token'] ?? '');

if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not found']);
    return;
}

@set_time_limit(0);

$force = ($_GET['force'] ?? '') === '1';
$result = $force
    ? AutoDrafter::runNow('schedule')
    : AutoDrafter::runIfDue('schedule');

$status = AutoDrafter::status();

echo json_encode([
    'ok'        => true,
    'ran'       => $result !== null,
    'skipped'   => $result !== null ? null : ($status['enabled'] ? 'already done for this slot' : 'automation paused'),
    'drafted'   => $result['drafted'] ?? 0,
    'lines'     => $result['lines'] ?? [],
    'aborted'   => $result['aborted'] ?? null,
    'enabled'   => $status['enabled'],
    'next_slot' => $status['next_slot']->format('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
