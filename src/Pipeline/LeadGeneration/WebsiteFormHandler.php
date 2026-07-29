<?php

declare(strict_types=1);

namespace App\Pipeline\LeadGeneration;

use App\AI\Memory\EpisodicLogger;
use App\Integrations\WhatsApp\CustomerServiceWindow;
use App\Integrations\WhatsApp\PhoneNumber;
use App\Integrations\WhatsApp\WhatsAppLink;
use App\Models\Lead;
use App\Models\Referral;
use App\Models\Room;
use App\Pipeline\Conversion\ConversationManager;

/**
 * Proposal channel #2 — website smart enquiry form. On submit: a leads row
 * with source_channel='website' (or 'referral' when they arrived through a
 * Refer & Earn link), then the WhatsApp follow-up.
 *
 * Room-listing enquiries (Phase 6.5) carry their context — which room, which
 * tenure — into the pipeline, so Eve's first WhatsApp message already knows
 * what the visitor was looking at. A generic "hi, how can I help?" would
 * waste the channel.
 *
 * HOW THE FOLLOW-UP ACTUALLY REACHES THEM. A first-time visitor has never
 * messaged our number, so WhatsApp's 24-hour customer service window is shut
 * and Meta will not carry a free-form message to them — the send is refused,
 * or accepted and then silently dropped, which is worse. So:
 *
 *   - window open (they have messaged us in the last 24h) → Eve replies
 *     instantly through the pipeline, exactly as before;
 *   - window shut (the normal case for a new visitor) → we hand back a wa.me
 *     deep link with their enquiry prefilled. They tap send, which opens the
 *     window from their side, and the inbound webhook runs the same pipeline
 *     with the room context already attached to their lead.
 *
 * The alternative — an approved Meta message template for first contact — is a
 * WABA-side approval, not a code change; see docs/setup_guide.md.
 */
final class WebsiteFormHandler
{
    /**
     * @param array{name:string, wa_phone:string, message?:string, ref_code?:string,
     *              room_id?:int|string, tenure?:string} $input
     * @return array{ok:bool, error?:string, lead_id?:int, wa_link?:string, delivered?:bool}
     */
    public static function handle(array $input): array
    {
        $name = trim($input['name'] ?? '');
        // '012-345 6789' is how a Malaysian types their own number; Meta only
        // accepts '60123456789'.
        $phone = PhoneNumber::normalize($input['wa_phone'] ?? '');
        $message = trim($input['message'] ?? '');
        $refCode = strtoupper(trim($input['ref_code'] ?? ''));

        $room = null;
        if (!empty($input['room_id'])) {
            $room = Room::find((int) $input['room_id']);
        }
        $tenure = in_array($input['tenure'] ?? '', Room::TENURES, true) ? $input['tenure'] : null;

        if ($name === '' || ($message === '' && $room === null)) {
            return ['ok' => false, 'error' => 'Name and message are required.'];
        }
        if (!PhoneNumber::isValid($phone)) {
            return ['ok' => false, 'error' => 'Enter a valid WhatsApp number, e.g. 012-345 6789 or 60123456789.'];
        }

        $channel = $refCode !== '' && Referral::findByCode($refCode) !== null ? 'referral' : 'website';
        $lead = Lead::findOrCreate($phone, $name, $channel);
        $leadId = (int) $lead['id'];

        if ($channel === 'referral') {
            Referral::attachReferredLead($refCode, $leadId);
            Lead::update($leadId, ['referral_code_used' => $refCode]);
        }

        // Attach the room context to the lead itself so DecideSkill's
        // RoomRecommender leads with the exact room they were looking at.
        if ($room !== null) {
            Lead::update($leadId, array_filter([
                'enquired_room_id' => (int) $room['id'],
                'preferred_tenure' => $tenure,
                'location'         => $lead['location'] ?: $room['location'],
                'room_type'        => $lead['room_type'] ?: $room['room_type'],
            ], fn ($v) => $v !== null && $v !== ''));
        }

        EpisodicLogger::activity(
            'website_enquiry_received',
            'lead_gen',
            null,
            $leadId,
            ($room !== null ? "room {$room['room_code']} ({$room['location']})" . ($tenure !== null ? ", tenure $tenure" : '') . ' — ' : '')
            . mb_substr($message, 0, 250)
        );

        // The inbound message the pipeline sees carries the room context, so
        // Understand/Decide/Create all ground on it from message one.
        $text = $message !== '' ? $message : 'Hi, I am interested in this room.';
        if ($room !== null) {
            $text = sprintf(
                '[Website enquiry: %s — %s, %s, %s room%s] %s',
                $room['room_code'],
                $room['property_name'] ?: $room['name'],
                $room['location'],
                $room['room_type'],
                $tenure !== null ? ', considering ' . Room::TENURE_LABELS[$tenure] : '',
                $text
            );
        }

        // Shut window: nothing we send can arrive, so the customer opens the
        // conversation instead — same pipeline, one tap later.
        if (!CustomerServiceWindow::isOpenFor($leadId)) {
            EpisodicLogger::activity(
                'website_enquiry_handoff',
                'lead_gen',
                null,
                $leadId,
                'No open 24h WhatsApp window (first contact) — handed the visitor a prefilled '
                . 'wa.me link so their message opens the conversation. Eve replies on the inbound webhook.'
            );

            return [
                'ok'        => true,
                'lead_id'   => $leadId,
                'delivered' => false,
                'wa_link'   => WhatsAppLink::to(self::prefill($name, $room, $tenure, $message)),
            ];
        }

        (new ConversationManager())->handleInbound([
            'wa_phone'   => $phone,
            'name'       => $name,
            'text'       => $text,
            'message_id' => 'webform-' . bin2hex(random_bytes(6)),
            'timestamp'  => time(),
        ]);

        return ['ok' => true, 'lead_id' => $leadId, 'delivered' => true];
    }

    /**
     * What the visitor sees already typed in WhatsApp. Written the way a person
     * writes — but naming the room, so Eve grounds on it even if they send from
     * a different number than the one they typed into the form.
     */
    private static function prefill(string $name, ?array $room, ?string $tenure, string $message): string
    {
        if ($room === null) {
            return trim("Hi Eve! I'm $name and I'm looking for a room. $message");
        }

        return trim(sprintf(
            "Hi Eve! I'm %s. I'm interested in %s — %s, %s (%s room%s). %s",
            $name,
            $room['room_code'],
            $room['property_name'] ?: $room['name'],
            $room['location'],
            $room['room_type'],
            $tenure !== null ? ', ' . Room::TENURE_LABELS[$tenure] : '',
            $message
        ));
    }
}
