<?php

declare(strict_types=1);

/**
 * The booking confirmation is the one message we know reaches the number that
 * IS the tenant portal identity — so it is where the portal login link goes.
 */

use App\Integrations\WhatsApp\WhatsAppClient;
use App\Models\Booking;
use App\Models\Lead;

$capture = new class extends WhatsAppClient {
    public array $sent = [];

    public function sendText(string $toWaPhone, string $text): array
    {
        $this->sent[] = $text;

        return ['message_id' => 'portal.' . count($this->sent), 'dry_run' => true];
    }
};

$leadId = Lead::create([
    'wa_phone'       => '601166600001',
    'name'           => 'Portal Link Tenant',
    'source_channel' => 'whatsapp',
]);
$bookingId = Booking::create([
    'lead_id'          => $leadId,
    'viewing_datetime' => '2026-08-20 15:00:00',
    'status'           => 'confirmed',
]);

\App\Pipeline\Booking\ConfirmationSender::send(Booking::find($bookingId), 'mock-offline-stub', $capture);

$confirmation = $capture->sent[0] ?? '';
$expected = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8080', '/') . '/tenant/login';

check('the viewing confirmation still confirms the viewing',
    str_contains($confirmation, 'Viewing confirmed'), $confirmation);
check('the confirmation carries the tenant portal login link',
    str_contains($confirmation, $expected), $confirmation);
check('...and says which number to log in with',
    str_contains($confirmation, 'same WhatsApp number'), $confirmation);
check('the booking is marked as confirmation-sent',
    (int) Booking::find($bookingId)['confirmation_sent'] === 1);
