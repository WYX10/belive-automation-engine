<?php

declare(strict_types=1);

/**
 * Concurrency / context-isolation test (explicit competition requirement):
 * fires N simultaneous WhatsApp conversations at the webhook — each customer
 * with a unique identity, area and budget — then asserts no conversation
 * bled into another (no cross-contaminated leads, transcripts, or entities).
 *
 *   1. Start the server with parallel workers:
 *        PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:8080 -t public public/index.php
 *   2. php tests/concurrency_test.php [--url=http://127.0.0.1:8080]
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(APP_ROOT)->safeLoad();
require APP_ROOT . '/config/constants.php';

use App\Core\Database;

$url = 'http://127.0.0.1:8080';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--url=')) {
        $url = rtrim(substr($arg, 6), '/');
    }
}

// Six customers, each with a distinct area + budget, hitting simultaneously.
$suffix = (string) random_int(100, 999); // fresh identities per run
$customers = [
    ['phone' => "60181000$suffix", 'name' => 'Conc A', 'area' => 'Cheras',         'budget' => 550],
    ['phone' => "60182000$suffix", 'name' => 'Conc B', 'area' => 'Sentul',         'budget' => 600],
    ['phone' => "60183000$suffix", 'name' => 'Conc C', 'area' => 'Petaling Jaya',  'budget' => 800],
    ['phone' => "60184000$suffix", 'name' => 'Conc D', 'area' => 'Sepang',         'budget' => 500],
    ['phone' => "60185000$suffix", 'name' => 'Conc E', 'area' => 'Sri Kembangan',  'budget' => 650],
    ['phone' => "60186000$suffix", 'name' => 'Conc F', 'area' => 'Johor',          'budget' => 700],
];

$payloadFor = fn (array $c): string => json_encode([
    'object' => 'whatsapp_business_account',
    'entry'  => [[
        'id'      => '000000000000000',
        'changes' => [[
            'field' => 'messages',
            'value' => [
                'messaging_product' => 'whatsapp',
                'contacts' => [['profile' => ['name' => $c['name']], 'wa_id' => $c['phone']]],
                'messages' => [[
                    'from' => $c['phone'],
                    'id'   => 'wamid.CONC' . bin2hex(random_bytes(6)),
                    'timestamp' => (string) time(),
                    'type' => 'text',
                    'text' => ['body' => "Hi I'm looking for a room in {$c['area']}, budget RM{$c['budget']}"],
                ]],
            ],
        ]],
    ]],
]);

// Fire all six at once via curl_multi.
$multi = curl_multi_init();
$handles = [];
foreach ($customers as $i => $customer) {
    $ch = curl_init("$url/webhook/whatsapp");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payloadFor($customer),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 180,
    ]);
    curl_multi_add_handle($multi, $ch);
    $handles[$i] = $ch;
}

$start = microtime(true);
do {
    curl_multi_exec($multi, $running);
    curl_multi_select($multi, 0.05);
} while ($running > 0);
$elapsed = round(microtime(true) - $start, 2);

$httpOk = true;
foreach ($handles as $ch) {
    $httpOk = $httpOk && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
    curl_multi_remove_handle($multi, $ch);
}
curl_multi_close($multi);

echo count($customers) . " simultaneous conversations completed in {$elapsed}s\n\n";

// ---- assertions: strict per-customer isolation --------------------------------
$pass = 0;
$fail = 0;
$assert = function (string $name, bool $ok) use (&$pass, &$fail) {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . "\n";
    $ok ? $pass++ : $fail++;
};

$assert('all webhook calls returned 200', $httpOk);

foreach ($customers as $customer) {
    $lead = Database::run('SELECT * FROM leads WHERE wa_phone = ?', [$customer['phone']])->fetch();
    $assert("{$customer['name']}: lead row exists", $lead !== false);
    if ($lead === false) {
        continue;
    }

    $assert(
        "{$customer['name']}: own name + area + budget (no cross-contamination)",
        $lead['name'] === $customer['name']
            && strcasecmp((string) $lead['location'], $customer['area']) === 0
            && (int) $lead['budget'] === $customer['budget']
    );

    $messages = Database::run(
        "SELECT COALESCE(message_in, message_out) AS msg FROM ai_interactions
         WHERE lead_id = ? AND direction IN ('inbound','outbound')",
        [$lead['id']]
    )->fetchAll();

    // Contamination = another customer's name or inbound message text showing
    // up in THIS transcript. (Areas/prices can legitimately overlap via room
    // matching, so they are asserted on the lead row above, not free-text.)
    $contaminated = false;
    foreach ($messages as $row) {
        foreach ($customers as $other) {
            if ($other['phone'] === $customer['phone']) {
                continue;
            }
            $othersInbound = "room in {$other['area']}, budget RM{$other['budget']}";
            if (str_contains((string) $row['msg'], $other['name'])
                || str_contains((string) $row['msg'], $othersInbound)) {
                $contaminated = true;
            }
        }
    }
    $assert("{$customer['name']}: transcript free of other customers' messages", !$contaminated);

    $assert(
        "{$customer['name']}: got exactly one inbound + at least one reply",
        (int) Database::run("SELECT COUNT(*) FROM ai_interactions WHERE lead_id = ? AND direction='inbound'", [$lead['id']])->fetchColumn() === 1
        && (int) Database::run("SELECT COUNT(*) FROM ai_interactions WHERE lead_id = ? AND direction='outbound'", [$lead['id']])->fetchColumn() >= 1
    );
}

echo "\n" . str_repeat('─', 46) . "\n";
echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
