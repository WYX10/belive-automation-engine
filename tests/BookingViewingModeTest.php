<?php

declare(strict_types=1);

/**
 * Booking viewing-mode flow: a vague time ("tuesday afternoon") gets an EXACT
 * slot proposal plus the "video call or face-to-face?" question; the customer's
 * pick confirms the booking with the mode on the receipt — zero model calls
 * for the mode reply.
 */

use App\Core\Database;
use App\Pipeline\Booking\ViewingMode;
use App\Pipeline\Conversion\ConversationManager;

// Capturing WhatsApp stub — records outbound texts, never touches the network.
$waCapture = new class extends \App\Integrations\WhatsApp\WhatsAppClient {
    public array $sent = [];

    public function sendText(string $toWaPhone, string $text): array
    {
        $this->sent[] = $text;

        return ['message_id' => 'test.' . count($this->sent), 'dry_run' => true];
    }

    public function sendImage(string $toWaPhone, string $imageUrl, string $caption = ''): array
    {
        $this->sent[] = "[image] $imageUrl";

        return ['message_id' => 'test.img', 'dry_run' => true];
    }
};

// ---- mode detector ---------------------------------------------------------
check('detect: "video call please" → video_call', ViewingMode::detect('video call please') === ViewingMode::VIDEO_CALL);
check('detect: "online is fine" → video_call', ViewingMode::detect('online is fine') === ViewingMode::VIDEO_CALL);
check('detect: "face to face at the property" → in_person', ViewingMode::detect('face to face at the property') === ViewingMode::IN_PERSON);
check('detect: "i will come see the room" → in_person', ViewingMode::detect('i will come see the room') === ViewingMode::IN_PERSON);
check('detect: Malay "saya datang" → in_person', ViewingMode::detect('saya datang sabtu') === ViewingMode::IN_PERSON);
check('detect: plain time reply → null', ViewingMode::detect('tuesday 3pm can?') === null);
check('detect: both mentioned → null (ambiguous)', ViewingMode::detect('video call or in person, whichever la') === null);

// ---- vague time → exact slot proposal + mode question ----------------------
$manager = new ConversationManager($waCapture);
$phone = '60129990777';

$manager->handleInbound([
    'wa_phone'   => $phone,
    'name'       => 'Mode Tester',
    'text'       => 'i want to book a viewing this tuesday afternoon',
    'message_id' => 'wamid.modetest1',
    'timestamp'  => time(),
]);

$lead = Database::run('SELECT * FROM leads WHERE wa_phone = ?', [$phone])->fetch();
$booking = $lead ? Database::run('SELECT * FROM bookings WHERE lead_id = ? ORDER BY id DESC LIMIT 1', [$lead['id']])->fetch() : false;
$proposal = $waCapture->sent !== [] ? end($waCapture->sent) : '';

check('vague time creates a pending booking', $booking !== false && $booking['status'] === 'pending', json_encode($booking ?: 'no booking'));
check('pending booking has no viewing mode yet', $booking !== false && $booking['viewing_mode'] === null);
check('proposal states an exact clock time', (bool) preg_match('/\d{1,2}(:\d{2})?(am|pm)/i', $proposal), $proposal);
check('proposal names the day', (bool) preg_match('/monday|tuesday|wednesday|thursday|friday|saturday|sunday/i', $proposal), $proposal);
check('proposal asks video call vs in person', stripos($proposal, 'video call') !== false && stripos($proposal, 'in person') !== false, $proposal);

// ---- mode reply confirms instantly ----------------------------------------
$sentBefore = count($waCapture->sent);
$manager->handleInbound([
    'wa_phone'   => $phone,
    'name'       => 'Mode Tester',
    'text'       => 'video call please',
    'message_id' => 'wamid.modetest2',
    'timestamp'  => time(),
]);

$booking = Database::run('SELECT * FROM bookings WHERE lead_id = ? ORDER BY id DESC LIMIT 1', [$lead['id']])->fetch();
$lead = Database::run('SELECT * FROM leads WHERE id = ?', [$lead['id']])->fetch();
$confirmation = implode("\n", array_slice($waCapture->sent, $sentBefore));

check('mode reply confirms the booking', $booking !== false && $booking['status'] === 'confirmed', json_encode($booking ?: 'no booking'));
check('viewing mode stored as video_call', $booking !== false && $booking['viewing_mode'] === 'video_call');
check('confirmation receipt sent with mode line', str_contains($confirmation, 'Viewing confirmed') && stripos($confirmation, 'video call') !== false, $confirmation);
check('lead converted on confirmation', $lead !== false && $lead['status'] === 'converted', (string) ($lead['status'] ?? 'missing'));

// ---- time + mode in one message → confirmed outright -----------------------
$phone2 = '60129990778';
$manager->handleInbound([
    'wa_phone'   => $phone2,
    'name'       => 'One Shot',
    'text'       => 'can we do a video call viewing tomorrow 3pm?',
    'message_id' => 'wamid.modetest3',
    'timestamp'  => time(),
]);

$lead2 = Database::run('SELECT * FROM leads WHERE wa_phone = ?', [$phone2])->fetch();
$booking2 = $lead2 ? Database::run('SELECT * FROM bookings WHERE lead_id = ? ORDER BY id DESC LIMIT 1', [$lead2['id']])->fetch() : false;

check('time+mode in one message books outright', $booking2 !== false && $booking2['status'] === 'confirmed', json_encode($booking2 ?: 'no booking'));
check('one-shot mode stored', $booking2 !== false && $booking2['viewing_mode'] === 'video_call');
check('one-shot confirmation sent', str_contains(end($waCapture->sent), 'Viewing confirmed'), end($waCapture->sent));
