<?php

declare(strict_types=1);

/**
 * The website enquiry has to end up ON WhatsApp, not just in the database.
 *
 * Two things used to break that quietly: a number typed the Malaysian way was
 * sent to Meta as-is, and a first-time visitor was pushed a message into a
 * customer service window that was never open. Both failed without a mark on
 * the record — the dashboard showed a reply that no phone ever received.
 */

use App\Integrations\WhatsApp\CustomerServiceWindow;
use App\Integrations\WhatsApp\PhoneNumber;
use App\Integrations\WhatsApp\WebhookParser;
use App\Integrations\WhatsApp\WhatsAppClient;
use App\Models\Lead;
use App\Pipeline\LeadGeneration\WebsiteFormHandler;

// ---- 1. Numbers reach Meta in the only shape it accepts ----------------------
check('Local 012-345 6789 becomes 60123456789', PhoneNumber::normalize('012-345 6789') === '60123456789');
check('+60 12 345 6789 keeps its country code', PhoneNumber::normalize('+60 12 345 6789') === '60123456789');
check('0060123456789 drops the access code', PhoneNumber::normalize('0060123456789') === '60123456789');
check('Already-normalized number is untouched', PhoneNumber::normalize('60123456789') === '60123456789');
check('Trunk-zero-less 123456789 is completed', PhoneNumber::normalize('123456789') === '60123456789');
check('US 12125550123 is left alone', PhoneNumber::normalize('12125550123') === '12125550123');
check('Too-short number is rejected', PhoneNumber::isValid(PhoneNumber::normalize('12345')) === false);

// ---- 2. First contact hands over a wa.me link instead of pushing -------------
$_ENV['EVE_WA_NUMBER'] = '60109009615';

$result = WebsiteFormHandler::handle([
    'name'     => 'Window Shut Visitor',
    'wa_phone' => '012-345 6701',        // typed the local way, on purpose
    'message'  => 'Is it near the LRT?',
]);

check('Enquiry is accepted', ($result['ok'] ?? false) === true, json_encode($result));
check('Nothing is claimed as delivered', ($result['delivered'] ?? true) === false);
check('A prefilled WhatsApp link comes back', str_starts_with($result['wa_link'] ?? '', 'https://wa.me/60109009615?text='));
check(
    'The prefill carries their question',
    str_contains(rawurldecode($result['wa_link'] ?? ''), 'Is it near the LRT?'),
    $result['wa_link'] ?? ''
);

$lead = Lead::find((int) $result['lead_id']);
check('Lead is stored on the normalized number', ($lead['wa_phone'] ?? '') === '60123456701', $lead['wa_phone'] ?? '');
check('Lead is filed as a website lead', ($lead['source_channel'] ?? '') === 'website');

// ---- 3. An open window still gets the instant AI reply ----------------------
$openLeadId = Lead::create([
    'wa_phone'       => '60123456702',
    'name'           => 'Chatted Yesterday',
    'source_channel' => 'whatsapp',
]);
App\Core\Database::run(
    "INSERT INTO ai_interactions (lead_id, phase, skill, direction, message_in, created_at)
     VALUES (?, 'conversion', 'understand', 'inbound', 'hi', NOW() - INTERVAL 2 HOUR)",
    [$openLeadId]
);

check('Window is open two hours after they messaged', CustomerServiceWindow::isOpenFor($openLeadId));
check('Window is shut for someone who never did', CustomerServiceWindow::isOpenFor((int) $result['lead_id']) === false);

$replied = WebsiteFormHandler::handle([
    'name'     => 'Chatted Yesterday',
    'wa_phone' => '60123456702',
    'message'  => 'Still thinking about that room',
]);
check('Open window replies instead of handing over', ($replied['delivered'] ?? false) === true && !isset($replied['wa_link']));
check(
    'Eve actually answered in the transcript',
    App\Models\Interaction::forLead($openLeadId, 20) !== []
        && array_filter(
            App\Models\Interaction::forLead($openLeadId, 20),
            fn ($row) => $row['direction'] === 'outbound'
        ) !== []
);

// ---- 4. A refused send is never recorded as delivered -----------------------
$refused = ['message_id' => '', 'dry_run' => false, 'failed' => true, 'error' => '[131047] Re-engagement message'];
check('Refusal is spelled out in the reasoning', str_contains(WhatsAppClient::deliveryNote($refused), 'NOT DELIVERED'));
check('Refusal note quotes what Meta said', str_contains(WhatsAppClient::deliveryNote($refused), '131047'));
check('A real send adds no note', WhatsAppClient::deliveryNote(['dry_run' => false, 'failed' => false]) === '');
check(
    'A dry run says so',
    str_contains(WhatsAppClient::deliveryNote(['dry_run' => true, 'failed' => false]), 'dry-run')
);

// ---- 5. Meta's failed-delivery callback is read, not dropped ----------------
$statusPayload = [
    'object' => 'whatsapp_business_account',
    'entry'  => [[
        'changes' => [[
            'field' => 'statuses',
            'value' => [
                'statuses' => [
                    ['id' => 'wamid.OK', 'status' => 'delivered', 'recipient_id' => '60123456701'],
                    [
                        'id'           => 'wamid.BAD',
                        'status'       => 'failed',
                        'recipient_id' => '60123456701',
                        'errors'       => [[
                            'code'       => 131047,
                            'title'      => 'Re-engagement message',
                            'error_data' => ['details' => 'More than 24 hours since the last inbound message.'],
                        ]],
                    ],
                ],
            ],
        ]],
    ]],
];

$failures = WebhookParser::parseFailedStatuses($statusPayload);
check('Only the failed status is picked up', count($failures) === 1, json_encode($failures));
check('The failure carries Meta\'s reason', ($failures[0]['code'] ?? 0) === 131047);
check('The failure names the recipient', ($failures[0]['recipient'] ?? '') === '60123456701');
check('A status-only payload is still status-only', WebhookParser::isStatusOnly($statusPayload));
check('No failures in a payload without any', WebhookParser::parseFailedStatuses(['entry' => []]) === []);
