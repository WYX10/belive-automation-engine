<?php

declare(strict_types=1);

namespace App\Pipeline\Conversion;

use App\AI\Memory\EpisodicLogger;
use App\AI\Memory\LeadMemoryProfile;
use App\AI\Memory\MemoryRetriever;
use App\AI\Skills\AutomateSkill;
use App\AI\Skills\CreateSkill;
use App\AI\Skills\DecideSkill;
use App\AI\Skills\UnderstandSkill;
use App\Integrations\WhatsApp\WhatsAppClient;
use App\Models\Interaction;
use App\Models\Lead;

/**
 * Orchestrates one inbound WhatsApp message through Eve's full pipeline:
 *
 *   Understand → Decide → Create → Automate
 *
 * with per-customer memory (LeadMemoryProfile recall before the opening line)
 * and learned rules (MemoryRetriever) injected into Decide + Create — so a
 * lesson learned from feedback visibly changes the very next conversation.
 */
final class ConversationManager
{
    private WhatsAppClient $wa;

    public function __construct(?WhatsAppClient $wa = null)
    {
        $this->wa = $wa ?? new WhatsAppClient();
    }

    /** @param array{wa_phone:string, name:?string, text:string, message_id:string, timestamp:int} $message */
    public function handleInbound(array $message): void
    {
        $started = hrtime(true);

        $lead = Lead::findOrCreate($message['wa_phone'], $message['name'], 'whatsapp');
        $leadId = (int) $lead['id'];

        // Fast pre-route: pure pleasantries get an instant ack, no model calls.
        $pre = IntentClassifier::preClassify($message['text']);
        if ($pre === IntentClassifier::SMALLTALK || $pre === IntentClassifier::STOP) {
            $this->quickReply($lead, $message['text'], $pre);
            return;
        }

        // Per-customer memory runs BEFORE the reply is generated, so a
        // returning customer's opening line references their prior enquiry.
        $recallLine = LeadMemoryProfile::recallLine($lead);

        // 1. UNDERSTAND — intent + entities, logged as the inbound row.
        $history = Interaction::transcript($leadId, 12);
        $understanding = UnderstandSkill::run($leadId, $message['text'], $history);
        $understanding['message'] = $message['text'];

        Lead::mergeEnquiryDetails($leadId, $understanding['entities']);
        if ($understanding['tenant_profile'] !== null) {
            Lead::update($leadId, ['tenant_profile' => $understanding['tenant_profile']]);
        }
        $lead = Lead::find($leadId) + ['is_returning' => $lead['is_returning'] ?? false];

        // 2. Retrieve learned rules for this context (area first, else general).
        $contextTag = $understanding['entities']['location'] ?? $lead['location'] ?? null;
        $memory = MemoryRetriever::promptBlock(MemoryRetriever::forContext($contextTag));

        // 3. DECIDE — situational: qualification, matching, next action.
        $decision = DecideSkill::run($lead, $understanding, $memory);

        // Escalation short-circuits: honest human handoff, no AI bluffing.
        if ($decision['next_action'] === 'escalate') {
            $reply = EscalationHandler::escalate($lead, $decision['reasoning'], $message['text']);
            $sent = $this->wa->sendText($lead['wa_phone'], $reply);
            EpisodicLogger::log([
                'lead_id'      => $leadId,
                'phase'        => 'conversion',
                'skill'        => 'automate',
                'model_used'   => $decision['model'],
                'direction'    => 'outbound',
                'message_out'  => $reply,
                'message_kind' => 'escalation_notice',
                'reasoning'    => $decision['reasoning'] . ($sent['dry_run'] ? ' (dry-run)' : ''),
                'response_ms'  => (int) ((hrtime(true) - $started) / 1_000_000),
            ]);
            return;
        }

        // 4. CREATE — on-brand reply, grounded in inventory + learned rules.
        $reply = CreateSkill::reply($lead, $understanding, $decision, $memory, $recallLine);

        // 5. AUTOMATE — photos-first if decided, send, status + assessment.
        AutomateSkill::run(
            $lead,
            $decision,
            $reply['text'],
            $this->wa,
            'conversion',
            (int) ((hrtime(true) - $started) / 1_000_000)
        );

        // 6. Booking handoff (wired in Phase 7).
        if ($decision['next_action'] === 'book_viewing') {
            $this->handleBookingIntent($lead, $understanding, $decision, $message['text']);
        }
    }

    /** Booking flow — implemented in Phase 7 (Pipeline/Booking). */
    private function handleBookingIntent(array $lead, array $understanding, array $decision, string $rawText): void
    {
        EpisodicLogger::activity(
            'booking_intent_detected',
            'conversion',
            $decision['model'],
            (int) $lead['id'],
            'Booking pipeline arrives in Phase 7 — intent recorded.'
        );
    }

    private function quickReply(array $lead, string $text, string $pre): void
    {
        $leadId = (int) $lead['id'];

        EpisodicLogger::log([
            'lead_id'    => $leadId,
            'phase'      => 'conversion',
            'skill'      => 'understand',
            'model_used' => 'intent-classifier',
            'direction'  => 'inbound',
            'message_in' => $text,
            'intent'     => $pre,
            'reasoning'  => 'Pre-classified without model calls (trivial message).',
        ]);

        if ($pre === IntentClassifier::STOP) {
            Lead::update($leadId, ['notes' => trim(($lead['notes'] ?? '') . "\n[" . date('Y-m-d H:i') . '] Customer asked to stop messages.')]);
            $reply = 'Understood — I\'ll stop messaging. If you ever need a room again, just say hi. 👋';
        } else {
            $reply = IntentClassifier::smalltalkReply($lead['name']);
        }

        $sent = $this->wa->sendText($lead['wa_phone'], $reply);

        EpisodicLogger::log([
            'lead_id'      => $leadId,
            'phase'        => 'conversion',
            'skill'        => 'automate',
            'model_used'   => 'intent-classifier',
            'direction'    => 'outbound',
            'message_out'  => $reply,
            'message_kind' => 'reply',
            'reasoning'    => 'Canned ack for ' . $pre . ($sent['dry_run'] ? ' (dry-run)' : ''),
        ]);
    }
}
