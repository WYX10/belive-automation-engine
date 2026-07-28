<?php

declare(strict_types=1);

namespace App\Pipeline\LeadGeneration;

use App\AI\Memory\EpisodicLogger;
use App\Core\Settings;
use App\Integrations\Meta\MetaMessenger;
use App\Integrations\WhatsApp\WhatsAppLink;
use App\Models\SocialReply;

/**
 * Comment-to-WhatsApp. Someone comments on a beLive post (or DMs the Page)
 * and within seconds they have a private message holding a wa.me link that
 * opens a chat with Eve — where the real pipeline (availability, pricing,
 * booking) already lives. Social is the shop window, WhatsApp is the counter.
 *
 * On a comment two messages go out:
 *   1. a PUBLIC reply, because "check your DMs" under the post is what tells
 *      the other 200 readers this account answers,
 *   2. the PRIVATE reply carrying the link.
 * The public one is best-effort: if it fails the DM still goes, because the
 * DM is the one that matters. Meta permits the private reply exactly once per
 * comment, which is why SocialReply::claim() gates everything.
 *
 * The link carries a single-use token, prefilled into the customer's first
 * WhatsApp message, so SocialRefMerger can prove the tenant came from that
 * exact Instagram comment instead of guessing.
 */
final class CommentResponder
{
    /** Handle one parsed FB/IG comment. $leadId is null when it was not an enquiry. */
    public static function handleComment(array $comment, ?int $leadId, ?MetaMessenger $messenger = null): void
    {
        $commentId = (string) ($comment['comment_id'] ?? '');
        if ($commentId === '' || !self::shouldReply($leadId)) {
            return;
        }

        $platform = $comment['platform'];
        $rowId = SocialReply::claim(
            $platform,
            $commentId,
            'comment',
            (string) $comment['user_id'],
            $comment['user_name'] ?? null,
            $leadId
        );
        if ($rowId === null) {
            // Redelivery, or we already used our one private reply here.
            EpisodicLogger::activity('social_reply_skipped', 'lead_gen', 'social-autoresponder', $leadId, "Already answered {$platform} comment {$commentId}.");
            return;
        }

        $messenger ??= new MetaMessenger();
        $context = self::context($comment, $rowId, $platform);

        // Instagram refuses a public reply on a nested comment — aim at the parent.
        $publicTarget = ($comment['parent_id'] ?? '') !== '' ? (string) $comment['parent_id'] : $commentId;
        $publicText = self::render('social_comment_public_reply', $context);
        $public = $publicText === ''
            ? ['status' => 'skipped', 'error' => null]
            : self::attempt(fn () => $messenger->replyToComment($platform, $publicTarget, $publicText));

        $dmText = self::render('social_comment_dm', $context);
        $private = $dmText === ''
            ? ['status' => 'skipped', 'error' => null]
            : self::attempt(fn () => $messenger->privateReplyToComment($platform, $commentId, $dmText));

        self::finish($rowId, $leadId, $platform, 'comment', $dmText, $public, $private, $context['token']);
    }

    /** Handle one parsed FB/IG direct message — no public half, just the link. */
    public static function handleDirectMessage(array $dm, ?int $leadId, ?MetaMessenger $messenger = null): void
    {
        $messageId = (string) ($dm['message_id'] ?? '');
        if ($messageId === '' || !self::enabled()) {
            return;
        }

        $platform = $dm['platform'];
        $rowId = SocialReply::claim(
            $platform,
            $messageId,
            'direct_message',
            (string) $dm['user_id'],
            $dm['user_name'] ?? null,
            $leadId
        );
        if ($rowId === null) {
            return; // redelivery
        }

        $messenger ??= new MetaMessenger();
        $context = self::context($dm, $rowId, $platform);

        $text = self::render('social_dm_reply', $context);
        $private = $text === ''
            ? ['status' => 'skipped', 'error' => null]
            : self::attempt(fn () => $messenger->sendDirectMessage($platform, (string) $dm['user_id'], $text));

        self::finish($rowId, $leadId, $platform, 'direct_message', $text, ['status' => 'skipped', 'error' => null], $private, $context['token']);
    }

    /**
     * The wa.me link for a token. Public so the admin panel can show exactly
     * what a customer would receive.
     */
    public static function whatsappLink(string $token, string $platform): string
    {
        // No number configured means no prefill either, so attribution is lost.
        $prefill = strtr(Settings::get('social_wa_prefill', 'Hi beLive! I saw your {platform} post [{token}]'), [
            '{token}'    => $token,
            '{platform}' => ucfirst($platform),
        ]);

        return WhatsAppLink::to($prefill);
    }

    /** @return array{name:string, link:string, platform:string, token:string} */
    private static function context(array $event, int $rowId, string $platform): array
    {
        $token = SocialReply::attachToken($rowId) ?? '';

        return [
            'name'     => self::firstName($event['user_name'] ?? null),
            'link'     => self::whatsappLink($token, $platform),
            'platform' => ucfirst($platform),
            'token'    => $token,
        ];
    }

    private static function render(string $settingKey, array $context): string
    {
        $template = trim(Settings::get($settingKey, ''));
        if ($template === '') {
            return '';
        }

        return strtr($template, [
            '{name}'     => $context['name'],
            '{link}'     => $context['link'],
            '{platform}' => $context['platform'],
        ]);
    }

    /**
     * @param callable():array{id:string, dry_run:bool} $send
     * @return array{status:string, error:?string}
     */
    private static function attempt(callable $send): array
    {
        try {
            $result = $send();

            return ['status' => $result['dry_run'] ? 'simulated' : 'sent', 'error' => null];
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    /** Record the outcome on the row and in the episodic log. */
    private static function finish(
        int $rowId,
        ?int $leadId,
        string $platform,
        string $eventType,
        string $sentText,
        array $public,
        array $private,
        string $token
    ): void {
        SocialReply::recordOutcome($rowId, $public['status'], $private['status'], $private['error'] ?? $public['error']);

        $note = $private['status'] === 'failed'
            ? 'Private reply FAILED: ' . $private['error']
            : "Private reply {$private['status']}, public reply {$public['status']}.";

        EpisodicLogger::log([
            'lead_id'      => $leadId,
            'phase'        => 'lead_gen',
            'skill'        => 'automate',
            'model_used'   => 'social-autoresponder',
            'direction'    => 'outbound',
            'message_out'  => $sentText,
            'message_kind' => $eventType === 'comment' ? 'social_private_reply' : 'social_dm_reply',
            'reasoning'    => ucfirst($platform) . " {$eventType} answered with a WhatsApp hand-off link"
                . ($token !== '' ? " (attribution token $token)" : ' (no attribution token — set the WhatsApp number)')
                . ". $note",
        ]);
    }

    private static function enabled(): bool
    {
        return Settings::get('social_autoreply_enabled', '1') === '1';
    }

    /**
     * With scope 'enquiry' (the default) only comments CommentScanner judged
     * to be about renting get a DM — "nice pic 😍" is left alone, which is
     * both politer and safer under Meta's spam rules. 'all' answers everyone.
     */
    private static function shouldReply(?int $leadId): bool
    {
        if (!self::enabled()) {
            return false;
        }

        return $leadId !== null || Settings::get('social_reply_scope', 'enquiry') === 'all';
    }

    private static function firstName(?string $name): string
    {
        $first = trim(explode(' ', trim((string) $name))[0] ?? '');

        return $first === '' ? 'there' : $first;
    }
}
