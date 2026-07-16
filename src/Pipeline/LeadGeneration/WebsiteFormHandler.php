<?php

declare(strict_types=1);

namespace App\Pipeline\LeadGeneration;

use App\AI\Memory\EpisodicLogger;
use App\Models\Lead;
use App\Models\Referral;
use App\Models\Room;
use App\Pipeline\Conversion\ConversationManager;

/**
 * Proposal channel #2 — website smart enquiry form. On submit: a leads row
 * with source_channel='website' (or 'referral' when they arrived through a
 * Refer & Earn link), then an INSTANT AI WhatsApp follow-up through the same
 * conversation pipeline as a direct message.
 *
 * Room-listing enquiries (Phase 6.5) carry their context — which room, which
 * tenure — into the pipeline, so Eve's first WhatsApp message already knows
 * what the visitor was looking at. A generic "hi, how can I help?" would
 * waste the channel.
 */
final class WebsiteFormHandler
{
    /**
     * @param array{name:string, wa_phone:string, message?:string, ref_code?:string,
     *              room_id?:int|string, tenure?:string} $input
     * @return array{ok:bool, error?:string, lead_id?:int}
     */
    public static function handle(array $input): array
    {
        $name = trim($input['name'] ?? '');
        $phone = preg_replace('/[^0-9]/', '', $input['wa_phone'] ?? '');
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
        if (!preg_match('/^\d{9,15}$/', $phone)) {
            return ['ok' => false, 'error' => 'Enter a valid WhatsApp number (digits only, e.g. 60123456789).'];
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

        (new ConversationManager())->handleInbound([
            'wa_phone'   => $phone,
            'name'       => $name,
            'text'       => $text,
            'message_id' => 'webform-' . bin2hex(random_bytes(6)),
            'timestamp'  => time(),
        ]);

        return ['ok' => true, 'lead_id' => $leadId];
    }
}
