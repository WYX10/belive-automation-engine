<?php

declare(strict_types=1);

namespace App\Pipeline\LeadGeneration;

use App\AI\Memory\EpisodicLogger;
use App\Core\Database;
use App\Models\Lead;
use App\Models\SocialReply;

/**
 * Closes the loop between a social comment and a WhatsApp chat.
 *
 * A social lead has no phone number — CommentScanner files it under the
 * pseudo-handle 'ig:<id>'. When that person taps the wa.me link we DM'd them,
 * WhatsApp opens with our prefilled text, which carries a single-use token
 * (BL7A3F2C). This class spots the token on the first inbound message and
 * folds the social lead into the real WhatsApp one:
 *
 *   - the social conversation history moves across, so Eve's opening line can
 *     already reference what they asked on Instagram,
 *   - the WhatsApp lead's source_channel becomes 'social', so the dashboard
 *     attributes the tenant to the post that actually produced them,
 *   - the social lead keeps its row but points at its successor, so nobody
 *     appears twice in the leads list.
 *
 * A token is spent once. Forward the link to a friend and the friend is
 * simply a normal new WhatsApp lead — which is the honest outcome.
 */
final class SocialRefMerger
{
    private const PATTERN = '/\[?\b(BL[0-9A-F]{6})\b\]?/i';

    public static function extract(string $text): ?string
    {
        return preg_match(self::PATTERN, $text, $m) === 1 ? strtoupper($m[1]) : null;
    }

    /**
     * Remove the token from the customer's message before the AI reads it —
     * the pipeline should see "Hi beLive! I saw your Instagram post", not a
     * reference code it might try to interpret. Falls back to the original
     * text if stripping would leave nothing.
     */
    public static function strip(string $text): string
    {
        $stripped = trim(preg_replace(self::PATTERN, '', $text) ?? $text);
        $stripped = trim(preg_replace('/\s{2,}/', ' ', $stripped) ?? $stripped);

        return $stripped === '' ? $text : $stripped;
    }

    /**
     * Redeem whatever token the message carries. Returns the merged social
     * lead id, or null when there is no token, it was already spent, or it
     * belongs to this same lead.
     */
    public static function claim(string $text, int $whatsappLeadId): ?int
    {
        $token = self::extract($text);
        if ($token === null) {
            return null;
        }

        $row = SocialReply::findByToken($token);
        if ($row === null || !SocialReply::claimToken((int) $row['id'], $whatsappLeadId)) {
            return null; // unknown, or already redeemed by someone else
        }

        $socialLeadId = $row['lead_id'] === null ? null : (int) $row['lead_id'];
        $platform = ucfirst((string) $row['platform']);

        if ($socialLeadId === null || $socialLeadId === $whatsappLeadId) {
            // Nothing to merge (the comment never became a lead), but the
            // attribution itself is still worth recording.
            EpisodicLogger::activity(
                'social_lead_attributed',
                'lead_gen',
                'ref-token-merge',
                $whatsappLeadId,
                "Arrived on WhatsApp from a $platform {$row['event_type']} (token $token)."
            );

            return null;
        }

        self::merge($socialLeadId, $whatsappLeadId, $platform, $token);

        return $socialLeadId;
    }

    private static function merge(int $socialLeadId, int $whatsappLeadId, string $platform, string $token): void
    {
        $social = Lead::find($socialLeadId);
        if ($social === null) {
            return;
        }

        // History follows the person, not the handle.
        Database::run('UPDATE ai_interactions SET lead_id = ? WHERE lead_id = ?', [$whatsappLeadId, $socialLeadId]);
        Database::run('UPDATE ai_activity_log SET lead_id = ? WHERE lead_id = ?', [$whatsappLeadId, $socialLeadId]);

        // Anything they already told us on social fills the blanks here, but
        // never overwrites something they said on WhatsApp.
        $whatsapp = Lead::find($whatsappLeadId) ?? [];
        $carry = [];
        foreach (['name', 'location', 'budget', 'move_in_date', 'room_type', 'tenant_profile'] as $field) {
            if (trim((string) ($whatsapp[$field] ?? '')) === '' && trim((string) ($social[$field] ?? '')) !== '') {
                $carry[$field] = $social[$field];
            }
        }
        // The lead really came from the post — say so on the record.
        if (($whatsapp['source_channel'] ?? '') === 'whatsapp') {
            $carry['source_channel'] = 'social';
        }
        if ($carry !== []) {
            Lead::update($whatsappLeadId, $carry);
        }

        Lead::update($socialLeadId, [
            'merged_into_lead_id' => $whatsappLeadId,
            'notes' => trim(($social['notes'] ?? '') . "\n[" . date('Y-m-d H:i') . "] Merged into lead #$whatsappLeadId — same person, now on WhatsApp."),
        ]);

        EpisodicLogger::activity(
            'social_lead_merged',
            'lead_gen',
            'ref-token-merge',
            $whatsappLeadId,
            "$platform lead #$socialLeadId ({$social['wa_phone']}) arrived on WhatsApp and was merged (token $token)."
        );
    }
}
