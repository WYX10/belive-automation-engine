<?php

declare(strict_types=1);

namespace App\Pipeline\LeadGeneration;

use App\AI\Memory\EpisodicLogger;
use App\Models\Lead;
use App\Models\Referral;
use App\Pipeline\Conversion\ConversationManager;

/**
 * Proposal channel #2 — website smart enquiry form. On submit: a leads row
 * with source_channel='website' (or 'referral' when they arrived through a
 * Refer & Earn link), then an INSTANT AI WhatsApp follow-up through the same
 * conversation pipeline as a direct message.
 */
final class WebsiteFormHandler
{
    /**
     * @param array{name:string, wa_phone:string, message:string, ref_code?:string} $input
     * @return array{ok:bool, error?:string, lead_id?:int}
     */
    public static function handle(array $input): array
    {
        $name = trim($input['name'] ?? '');
        $phone = preg_replace('/[^0-9]/', '', $input['wa_phone'] ?? '');
        $message = trim($input['message'] ?? '');
        $refCode = strtoupper(trim($input['ref_code'] ?? ''));

        if ($name === '' || $message === '') {
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

        EpisodicLogger::activity('website_enquiry_received', 'lead_gen', null, $leadId, mb_substr($message, 0, 300));

        // Instant AI follow-up on WhatsApp — same pipeline as a direct message,
        // so scoring, memory and learned rules all apply from message one.
        (new ConversationManager())->handleInbound([
            'wa_phone'   => $phone,
            'name'       => $name,
            'text'       => $message,
            'message_id' => 'webform-' . bin2hex(random_bytes(6)),
            'timestamp'  => time(),
        ]);

        return ['ok' => true, 'lead_id' => $leadId];
    }
}
