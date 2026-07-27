<?php

declare(strict_types=1);

/**
 * Social auto-reply: an Instagram comment becomes a WhatsApp conversation.
 *
 * Nothing here touches Meta — a capturing MetaMessenger stands in for the
 * Graph API, so what is asserted is the logic that matters: the once-only
 * guarantee, who does and does not get a DM, the self-reply loop guard, and
 * the token round trip that proves a tenant came from a specific post.
 */

use App\Core\Database;
use App\Core\Settings;
use App\Integrations\Meta\CommentWebhookParser;
use App\Integrations\Meta\MessageWebhookParser;
use App\Integrations\Meta\MetaMessenger;
use App\Models\Lead;
use App\Models\SocialReply;
use App\Pipeline\LeadGeneration\CommentResponder;
use App\Pipeline\LeadGeneration\CommentScanner;
use App\Pipeline\LeadGeneration\SocialRefMerger;

// A number must be configured or the link cannot carry a token.
Settings::set('social_wa_number', '60123456789');

$messenger = new class extends MetaMessenger {
    public array $public = [];
    public array $private = [];
    public array $dms = [];

    public function isConfigured(string $platform): bool
    {
        return true;
    }

    public function isOwnAccount(string $platform, string $userId): bool
    {
        return $userId === 'ig-belive-account';
    }

    public function replyToComment(string $platform, string $commentId, string $text): array
    {
        $this->public[] = ['target' => $commentId, 'text' => $text];

        return ['id' => 'pub.' . count($this->public), 'dry_run' => false];
    }

    public function privateReplyToComment(string $platform, string $commentId, string $text): array
    {
        $this->private[] = ['comment_id' => $commentId, 'text' => $text];

        return ['id' => 'prv.' . count($this->private), 'dry_run' => false];
    }

    public function sendDirectMessage(string $platform, string $recipientId, string $text): array
    {
        $this->dms[] = ['to' => $recipientId, 'text' => $text];

        return ['id' => 'dm.' . count($this->dms), 'dry_run' => false];
    }
};

/** Feed a raw webhook payload through exactly what public/webhook/whatsapp.php does. */
$deliver = function (array $payload) use ($messenger): void {
    foreach (CommentWebhookParser::parse($payload) as $comment) {
        if ($messenger->isOwnAccount($comment['platform'], $comment['user_id'])) {
            continue;
        }
        CommentResponder::handleComment($comment, CommentScanner::capture($comment), $messenger);
    }
    foreach (MessageWebhookParser::parse($payload) as $dm) {
        if ($messenger->isOwnAccount($dm['platform'], $dm['user_id'])) {
            continue;
        }
        CommentResponder::handleDirectMessage($dm, CommentScanner::capture($dm, 'direct_message'), $messenger);
    }
};

$igComment = static fn (string $commentId, string $text, string $from = 'ig-user-777', array $extra = []): array => [
    'object' => 'instagram',
    'entry'  => [[
        'id'      => 'ig-belive-account',
        'changes' => [[
            'field' => 'comments',
            'value' => [
                'id'   => $commentId,
                'text' => $text,
                'from' => ['id' => $from, 'username' => 'Aiman Tan'],
            ] + $extra,
        ]],
    ]],
];

// ---- an enquiry comment gets both halves ------------------------------------
$deliver($igComment('ig.c1', 'hi is this room still available? how much per month?'));

check('an enquiry comment gets a public reply', count($messenger->public) === 1, json_encode($messenger->public));
check('an enquiry comment gets a private reply', count($messenger->private) === 1, json_encode($messenger->private));
check('the public reply points people at their inbox',
    str_contains(strtolower($messenger->public[0]['text'] ?? ''), 'dm'), $messenger->public[0]['text'] ?? '');
check('the public reply greets them by first name',
    str_contains($messenger->public[0]['text'] ?? '', 'Aiman'), $messenger->public[0]['text'] ?? '');

$dmText = $messenger->private[0]['text'] ?? '';
check('the private reply carries a wa.me link', str_contains($dmText, 'https://wa.me/60123456789'), $dmText);
check('the link prefills a first message', str_contains($dmText, '?text='), $dmText);

$row = SocialReply::first(['object_id' => 'ig.c1']);
$token = (string) ($row['ref_token'] ?? '');
check('the event is recorded with a token', preg_match('/^BL[0-9A-F]{6}$/', $token) === 1, $token);
check('the token travels inside the link', str_contains(rawurldecode($dmText), $token), $dmText);
check('both replies are recorded as sent',
    ($row['public_reply'] ?? '') === 'sent' && ($row['private_reply'] ?? '') === 'sent',
    json_encode([$row['public_reply'] ?? '', $row['private_reply'] ?? '']));

// ---- Meta redelivers the same comment ---------------------------------------
$deliver($igComment('ig.c1', 'hi is this room still available? how much per month?'));
check('a redelivered comment is not answered twice',
    count($messenger->private) === 1 && count($messenger->public) === 1,
    json_encode(['public' => count($messenger->public), 'private' => count($messenger->private)]));
check('the redelivery did not create a second event row',
    SocialReply::count(['object_id' => 'ig.c1']) === 1);

// ---- chatter is left alone ---------------------------------------------------
$deliver($igComment('ig.c2', 'omg so nice 😍', 'ig-user-888'));
check('a non-enquiry comment gets no DM', count($messenger->private) === 1);
check('a non-enquiry comment is not even recorded as answered',
    SocialReply::first(['object_id' => 'ig.c2']) === null);

// ---- scope 'all' answers everyone --------------------------------------------
Settings::set('social_reply_scope', 'all');
$deliver($igComment('ig.c3', 'omg so nice 😍', 'ig-user-889'));
check('scope=all answers chatter too', count($messenger->private) === 2);
Settings::set('social_reply_scope', 'enquiry');

// ---- our own comment must never trigger a reply ------------------------------
$before = count($messenger->private);
$deliver($igComment('ig.c4', 'Hi! Sent you a DM with the details — room still available', 'ig-belive-account'));
check('our own account commenting does not start a reply loop', count($messenger->private) === $before);

// ---- a nested comment is replied to on its parent ----------------------------
$deliver($igComment('ig.c5', 'whats the price for the master room?', 'ig-user-890', ['parent' => ['id' => 'ig.c1']]));
check('a public reply on a nested comment targets the parent',
    end($messenger->public)['target'] === 'ig.c1', json_encode(end($messenger->public)));
check('the private reply still targets the actual comment',
    end($messenger->private)['comment_id'] === 'ig.c5', json_encode(end($messenger->private)));

// ---- direct messages ----------------------------------------------------------
$dmPayload = [
    'object' => 'instagram',
    'entry'  => [[
        'id'        => 'ig-belive-account',
        'messaging' => [
            ['sender' => ['id' => 'ig-user-901'], 'message' => ['mid' => 'ig.m1', 'text' => 'hello, any room in setapak?']],
            // Our own outbound DM echoing back — answering it would loop.
            ['sender' => ['id' => 'ig-belive-account'], 'message' => ['mid' => 'ig.m2', 'text' => 'Hi! chat with us', 'is_echo' => true]],
            // A read receipt carries no message at all.
            ['sender' => ['id' => 'ig-user-902'], 'read' => ['mid' => 'ig.m1']],
        ],
    ]],
];
$deliver($dmPayload);
check('a direct message gets exactly one reply', count($messenger->dms) === 1, json_encode($messenger->dms));
check('the DM reply carries the WhatsApp link', str_contains($messenger->dms[0]['text'] ?? '', 'https://wa.me/'), $messenger->dms[0]['text'] ?? '');
check('an echo of our own DM is ignored', MessageWebhookParser::parse($dmPayload) !== []
    && count(MessageWebhookParser::parse($dmPayload)) === 1);
check('a DM is captured as a lead even without enquiry keywords',
    Lead::findByPhone('ig:ig-user-901') !== null);

$deliver($dmPayload);
check('a redelivered DM is not answered twice', count($messenger->dms) === 1);

// ---- the token round trip: Instagram comment → WhatsApp chat ------------------
$socialLead = Lead::findByPhone('ig:ig-user-777');
check('the enquiry comment created a social lead', $socialLead !== null);
$socialLeadId = (int) $socialLead['id'];
$socialInteractions = (int) Database::run('SELECT COUNT(*) FROM ai_interactions WHERE lead_id = ?', [$socialLeadId])->fetchColumn();
check('the social lead holds the comment history', $socialInteractions > 0, (string) $socialInteractions);

check('the token is found in a natural first message',
    SocialRefMerger::extract("Hi beLive! I saw your Instagram post [$token]") === $token);
check('the code is stripped before the AI reads the message',
    SocialRefMerger::strip("Hi beLive! I saw your Instagram post [$token]") === 'Hi beLive! I saw your Instagram post');
check('a message with no token strips to itself',
    SocialRefMerger::strip('hi, any rooms in cheras?') === 'hi, any rooms in cheras?');

$waCapture = new class extends \App\Integrations\WhatsApp\WhatsAppClient {
    public array $sent = [];

    public function sendText(string $toWaPhone, string $text): array
    {
        $this->sent[] = $text;

        return ['message_id' => 'social.' . count($this->sent), 'dry_run' => true];
    }

    public function sendImage(string $toWaPhone, string $imageUrl, string $caption = ''): array
    {
        return ['message_id' => 'social.img', 'dry_run' => true];
    }
};

$phone = '60129990777';
(new \App\Pipeline\Conversion\ConversationManager($waCapture))->handleInbound([
    'wa_phone'   => $phone,
    'name'       => 'Aiman',
    'text'       => "Hi beLive! I saw your Instagram post [$token]",
    'message_id' => 'wamid.social1',
    'timestamp'  => time(),
]);

$waLead = Lead::findByPhone($phone);
check('the WhatsApp lead exists', $waLead !== null);
$waLeadId = (int) $waLead['id'];

check('the lead is attributed to social, not WhatsApp',
    ($waLead['source_channel'] ?? '') === 'social', (string) ($waLead['source_channel'] ?? ''));
check('the social lead is marked as merged',
    (int) (Lead::find($socialLeadId)['merged_into_lead_id'] ?? 0) === $waLeadId);
check('the Instagram history moved to the WhatsApp lead',
    (int) Database::run('SELECT COUNT(*) FROM ai_interactions WHERE lead_id = ?', [$socialLeadId])->fetchColumn() === 0);
check('the WhatsApp lead now owns that history',
    (int) Database::run('SELECT COUNT(*) FROM ai_interactions WHERE lead_id = ?', [$waLeadId])->fetchColumn() > $socialInteractions);

$claimed = SocialReply::first(['object_id' => 'ig.c1']);
check('the event records who redeemed the token',
    (int) ($claimed['claimed_by_lead_id'] ?? 0) === $waLeadId);
check('and when', ($claimed['claimed_at'] ?? null) !== null);
check('Eve replied to the customer on WhatsApp', $waCapture->sent !== []);

// ---- a forwarded link cannot steal the attribution ---------------------------
$friendPhone = '60129990778';
(new \App\Pipeline\Conversion\ConversationManager($waCapture))->handleInbound([
    'wa_phone'   => $friendPhone,
    'name'       => 'Friend',
    'text'       => "Hi beLive! I saw your Instagram post [$token]",
    'message_id' => 'wamid.social2',
    'timestamp'  => time(),
]);
$friend = Lead::findByPhone($friendPhone);
check('a second person using the same token is just a normal lead',
    ($friend['source_channel'] ?? '') === 'whatsapp', (string) ($friend['source_channel'] ?? ''));
check('the token still points at the first claimant',
    (int) (SocialReply::first(['object_id' => 'ig.c1'])['claimed_by_lead_id'] ?? 0) === $waLeadId);

// ---- disabled means silent ----------------------------------------------------
Settings::set('social_autoreply_enabled', '0');
$before = count($messenger->private);
$deliver($igComment('ig.c9', 'how much is the rent for a single room?', 'ig-user-903'));
check('switching auto-reply off stops the DMs', count($messenger->private) === $before);
check('but the lead is still captured', Lead::findByPhone('ig:ig-user-903') !== null);
Settings::set('social_autoreply_enabled', '1');
