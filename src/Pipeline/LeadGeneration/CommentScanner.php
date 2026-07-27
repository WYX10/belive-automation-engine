<?php

declare(strict_types=1);

namespace App\Pipeline\LeadGeneration;

use App\AI\Memory\EpisodicLogger;
use App\Models\Lead;

/**
 * Captures FB/IG comments and direct messages as leads. Social users have no
 * WhatsApp number yet, so identity is a 'fb:<id>' / 'ig:<id>' pseudo-handle —
 * when they later message on WhatsApp, SocialRefMerger folds this lead into
 * their real number. Every capture is scored by LeadScorer so the pipeline
 * downstream is identical to every other channel.
 */
final class CommentScanner
{
    /**
     * @param array{platform:string, user_id:string, user_name:?string, text:string, comment_id?:string, message_id?:string} $comment
     * @param string $kind 'comment' or 'direct_message'
     */
    public static function capture(array $comment, string $kind = 'comment'): ?int
    {
        // Only comments that look like rental interest become leads; pure
        // chatter ("nice!") is logged but not captured. A DM is never filtered:
        // opening a private conversation IS the intent signal.
        if ($kind === 'comment' && !self::looksLikeEnquiry($comment['text'])) {
            EpisodicLogger::activity(
                'social_comment_skipped',
                'lead_gen',
                null,
                null,
                mb_substr("[{$comment['platform']}] {$comment['text']}", 0, 300)
            );

            return null;
        }

        $handle = ($comment['platform'] === 'instagram' ? 'ig:' : 'fb:') . $comment['user_id'];
        $lead = Lead::findOrCreate($handle, $comment['user_name'], 'social');
        $leadId = (int) $lead['id'];

        $objectId = (string) ($comment['comment_id'] ?? $comment['message_id'] ?? '');
        EpisodicLogger::log([
            'lead_id'      => $leadId,
            'phase'        => 'lead_gen',
            'skill'        => 'understand',
            'model_used'   => 'webhook-capture',
            'direction'    => 'inbound',
            'message_in'   => $comment['text'],
            'message_kind' => $kind === 'comment' ? 'social_comment' : 'social_dm',
            'reasoning'    => "Captured from {$comment['platform']} " . str_replace('_', ' ', $kind) . " $objectId.",
        ]);

        // Score it like any other lead (uses the lead_gen phase model).
        try {
            LeadScorer::score($leadId, $comment['text']);
        } catch (\Throwable $e) {
            // Scoring failure must not lose the captured lead.
            EpisodicLogger::activity('lead_scoring_failed', 'lead_gen', null, $leadId, $e->getMessage());
        }

        return $leadId;
    }

    private static function looksLikeEnquiry(string $text): bool
    {
        return (bool) preg_match(
            '/\b(room|rent|rental|sewa|bilik|price|harga|how much|berapa|available|dm|pm|interested|book|viewing|deposit)\b/iu',
            $text
        );
    }
}
