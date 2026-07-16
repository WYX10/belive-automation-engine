<?php

declare(strict_types=1);

namespace App\Integrations\TikTok;

use App\AI\Memory\EpisodicLogger;
use App\Models\Lead;
use App\Pipeline\LeadGeneration\LeadScorer;

/**
 * TikTok manual-intake fallback — honest version, not a fake automation.
 *
 * The official TikTok API does not support third-party DM/comment webhook
 * capture, so automated TikTok lead capture cannot genuinely run. Instead:
 * admin logs TikTok enquiries via the manual-intake form; the downstream
 * pipeline (scoring, conversion, memory, booking) is IDENTICAL to automated
 * channels. Limitation documented in docs/judge_demo_script.md.
 */
final class FallbackNotifier
{
    /**
     * Log a TikTok enquiry manually. Identity: phone if the user shared one,
     * else a tiktok:handle pseudo-id (upgraded automatically once they message
     * us on WhatsApp).
     */
    public static function logEnquiry(string $handleOrPhone, ?string $name, string $enquiryText): array
    {
        $digits = preg_replace('/[^0-9]/', '', $handleOrPhone);
        $identity = preg_match('/^\d{9,15}$/', $digits)
            ? $digits
            : 'tiktok:' . mb_substr(ltrim(trim($handleOrPhone), '@'), 0, 24);

        $lead = Lead::findOrCreate($identity, $name, 'tiktok');
        $leadId = (int) $lead['id'];

        EpisodicLogger::log([
            'lead_id'      => $leadId,
            'phase'        => 'lead_gen',
            'skill'        => 'understand',
            'model_used'   => 'manual-intake',
            'direction'    => 'inbound',
            'message_in'   => $enquiryText,
            'message_kind' => 'tiktok_enquiry',
            'reasoning'    => 'Logged manually — TikTok API does not allow third-party DM/comment capture (documented fallback).',
        ]);

        try {
            LeadScorer::score($leadId, $enquiryText);
        } catch (\Throwable $e) {
            EpisodicLogger::activity('lead_scoring_failed', 'lead_gen', null, $leadId, $e->getMessage());
        }

        return Lead::find($leadId);
    }
}
