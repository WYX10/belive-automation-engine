<?php

declare(strict_types=1);

/**
 * Local WhatsApp webhook simulator — posts a genuine Meta Cloud API-shaped
 * inbound payload at the local webhook, exactly as Meta would. Lets the whole
 * pipeline be exercised without a public URL or a real phone.
 *
 *   php tests/simulate_whatsapp.php "<message text>" [wa_phone] [name] [--url=http://127.0.0.1:8080]
 *
 * This drives the REAL code path (webhook → parser → conversation pipeline →
 * WhatsAppClient). With no WhatsApp credential configured the outbound reply
 * lands in ai_activity_log as a clearly-labelled dry-run row.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}

$args = array_values(array_filter(array_slice($argv, 1), fn ($a) => !str_starts_with($a, '--')));
$opts = array_values(array_filter(array_slice($argv, 1), fn ($a) => str_starts_with($a, '--')));

$text = $args[0] ?? 'Hi, any room in Setapak below RM700?';
$phone = $args[1] ?? '60129990001';
$name = $args[2] ?? 'Sim Tester';

$url = 'http://127.0.0.1:8080';
foreach ($opts as $opt) {
    if (str_starts_with($opt, '--url=')) {
        $url = rtrim(substr($opt, 6), '/');
    }
}

$payload = [
    'object' => 'whatsapp_business_account',
    'entry'  => [[
        'id'      => '000000000000000',
        'changes' => [[
            'field' => 'messages',
            'value' => [
                'messaging_product' => 'whatsapp',
                'metadata'          => ['display_phone_number' => '60300000000', 'phone_number_id' => '111111111111111'],
                'contacts'          => [['profile' => ['name' => $name], 'wa_id' => $phone]],
                'messages'          => [[
                    'from'      => $phone,
                    'id'        => 'wamid.SIM' . bin2hex(random_bytes(8)),
                    'timestamp' => (string) time(),
                    'type'      => 'text',
                    'text'      => ['body' => $text],
                ]],
            ],
        ]],
    ]],
];

$ch = curl_init("$url/webhook/whatsapp");
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 120,
]);
$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$err = curl_error($ch);
curl_close($ch);

echo "POST $url/webhook/whatsapp\n";
echo "  from  : $name <$phone>\n";
echo "  text  : $text\n";
echo $err !== '' ? "  ERROR : $err\n" : "  HTTP  : $status — $response\n";
