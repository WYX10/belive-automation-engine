<?php

declare(strict_types=1);

namespace App\Pipeline\Conversion;

use App\AI\Memory\EpisodicLogger;
use App\AI\Memory\FeedbackCollector;
use App\AI\Memory\LeadMemoryProfile;
use App\AI\Memory\LearningEngine;
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

        // 1b. Implicit feedback — customer corrections and repeated questions.
        // Corrections are distilled into learned rules SYNCHRONOUSLY, before
        // memory retrieval below, so even this very reply already honours the
        // correction the customer just made.
        foreach (FeedbackCollector::detectImplicit($leadId, $understanding, $history) as $feedbackId) {
            try {
                LearningEngine::processFeedback($feedbackId);
            } catch (\Throwable $e) {
                EpisodicLogger::activity('rule_learning_failed', 'conversion', null, $leadId, $e->getMessage());
            }
        }

        // 2. Retrieve learned rules for this context (area first, else general).
        $contextTag = $understanding['entities']['location'] ?? $lead['location'] ?? null;
        $memory = MemoryRetriever::promptBlock(MemoryRetriever::forContext($contextTag));

        // 3. DECIDE — situational: qualification, matching, next action.
        $decision = DecideSkill::run($lead, $understanding, $memory, 'conversion', $history);

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
        $reply = CreateSkill::reply($lead, $understanding, $decision, $memory, $recallLine, 'conversion', $history);

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

    /**
     * Zero-touch booking: parse the natural-language time, cross-check the
     * live schedule, create + confirm, or propose the nearest free slot.
     */
    private function handleBookingIntent(array $lead, array $understanding, array $decision, string $rawText): void
    {
        $leadId = (int) $lead['id'];

        $parsed = \App\Pipeline\Booking\AvailabilityChecker::parseRequestedTime($rawText);
        if ($parsed['datetime'] === null) {
            // No concrete time proposed — CreateSkill's reply already asked
            // for one; nothing to book yet.
            EpisodicLogger::activity('booking_intent_detected', 'conversion', $decision['model'], $leadId, 'No concrete time yet — asked customer.');
            return;
        }

        $roomId = $decision['recommended_room_ids'][0] ?? ($decision['rooms'][0]['id'] ?? null);
        $roomId = $roomId !== null ? (int) $roomId : null;

        if (!\App\Pipeline\Booking\AvailabilityChecker::isSlotFree($roomId, $parsed['datetime'])) {
            $alternative = \App\Pipeline\Booking\AvailabilityChecker::suggestAlternative($roomId, $parsed['datetime']);
            $friendly = date('l, j M \a\t g:ia', strtotime($alternative));
            $reply = "That slot's just been taken — closest free one is $friendly. Shall I lock it in?";
            $sent = $this->wa->sendText($lead['wa_phone'], $reply);
            EpisodicLogger::log([
                'lead_id'      => $leadId,
                'phase'        => 'conversion',
                'skill'        => 'automate',
                'model_used'   => $decision['model'],
                'direction'    => 'outbound',
                'message_out'  => $reply,
                'message_kind' => 'question',
                'reasoning'    => 'Requested slot clashed with an existing booking; suggested nearest free slot.'
                    . ($sent['dry_run'] ? ' (dry-run)' : ''),
            ]);
            return;
        }

        $booking = \App\Pipeline\Booking\BookingCreator::create(
            $leadId,
            $roomId,
            $parsed['datetime'],
            $decision['model'],
            'Auto-booked from conversation. Time parse: ' . $parsed['reasoning']
        );

        \App\Pipeline\Booking\ConfirmationSender::send($booking, $decision['model'], $this->wa);
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
