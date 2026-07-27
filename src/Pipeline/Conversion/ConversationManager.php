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
use App\Pipeline\LeadGeneration\SocialRefMerger;

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

        // Arrived from an Instagram/Facebook auto-reply? The wa.me link we DM'd
        // prefilled a one-time token into this very message: redeem it, absorb
        // the social lead's history, then strip the code so the AI never sees
        // it. Done BEFORE recall, so Eve's first line can already reference
        // what they asked under the post.
        if (SocialRefMerger::claim($message['text'], $leadId) !== null) {
            $lead = Lead::find($leadId) + ['is_returning' => true];
        }
        $message['text'] = SocialRefMerger::strip($message['text']);

        // Fast pre-route: pure pleasantries get an instant ack, no model calls.
        $pre = IntentClassifier::preClassify($message['text']);
        if ($pre === IntentClassifier::SMALLTALK || $pre === IntentClassifier::STOP) {
            $this->quickReply($lead, $message['text'], $pre);
            return;
        }

        // Viewing-mode reply: Eve proposed an exact slot and asked "video call
        // or face-to-face?" — a clear pick confirms the booking instantly,
        // zero model calls.
        if ($this->handleModeReply($lead, $message['text'])) {
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

        // 4. Booking path: when the customer proposed/agreed a time, the
        // booking pipeline owns the reply — one message with the EXACT slot
        // plus the viewing-mode question (or the full confirmation when the
        // mode is already known). Falls through to the normal reply when no
        // concrete time could be parsed yet, so CreateSkill asks for one.
        if ($decision['next_action'] === 'book_viewing'
            && $this->handleBookingIntent($lead, $decision, $message['text'])) {
            if ($decision['qualified'] && $lead['status'] === 'new') {
                Lead::transition($leadId, 'qualified');
            }
            Lead::setAiAssessment(
                $leadId,
                $decision['closing_probability'],
                $decision['lead_signals'],
                $decision['recommendation']
            );
            return;
        }

        // 5. CREATE — on-brand reply, grounded in inventory + learned rules.
        $reply = CreateSkill::reply($lead, $understanding, $decision, $memory, $recallLine, 'conversion', $history);

        // 6. AUTOMATE — photos-first if decided, send, status + assessment.
        AutomateSkill::run(
            $lead,
            $decision,
            $reply['text'],
            $this->wa,
            'conversion',
            (int) ((hrtime(true) - $started) / 1_000_000)
        );
    }

    /**
     * Zero-touch booking: parse the natural-language time, cross-check the
     * live schedule, then either confirm outright (mode already stated),
     * propose the exact slot + ask video call vs face-to-face, or offer the
     * nearest free slot on a clash. Returns false only when no concrete time
     * could be parsed — the caller then falls back to the normal reply.
     */
    private function handleBookingIntent(array $lead, array $decision, string $rawText): bool
    {
        $leadId = (int) $lead['id'];

        $parsed = \App\Pipeline\Booking\AvailabilityChecker::parseRequestedTime($rawText);
        if ($parsed['datetime'] === null) {
            // No concrete time proposed — CreateSkill's reply asks for one.
            EpisodicLogger::activity('booking_intent_detected', 'conversion', $decision['model'], $leadId, 'No concrete time yet — asking customer.');
            return false;
        }

        $roomId = $decision['recommended_room_ids'][0] ?? ($decision['rooms'][0]['id'] ?? null);
        $roomId = $roomId !== null ? (int) $roomId : null;

        // The mode (if the customer already stated one) narrows which agents
        // can take the slot, so detect it before the availability cross-check.
        $mode = \App\Pipeline\Booking\ViewingMode::detect($rawText);

        if (!\App\Pipeline\Booking\AvailabilityChecker::isSlotFree($roomId, $parsed['datetime'], $mode)) {
            $alternative = \App\Pipeline\Booking\AvailabilityChecker::suggestAlternative($roomId, $parsed['datetime'], $mode);
            $friendly = date('l, j M \a\t g:ia', strtotime($alternative));

            // Say which kind of clash it was — a taken room reads very
            // differently from "nobody is rostered at that hour".
            $roomTaken = $roomId !== null && \App\Models\Booking::conflictsAt($roomId, $parsed['datetime']) !== [];
            $reply = $roomTaken
                ? "That slot's just been taken — closest free one is $friendly. Shall I lock it in?"
                : "None of our team is free at that time — the earliest we can host you is $friendly. Shall I lock it in?";
            $sent = $this->wa->sendText($lead['wa_phone'], $reply);
            EpisodicLogger::log([
                'lead_id'      => $leadId,
                'phase'        => 'conversion',
                'skill'        => 'automate',
                'model_used'   => $decision['model'],
                'direction'    => 'outbound',
                'message_out'  => $reply,
                'message_kind' => 'question',
                'reasoning'    => ($roomTaken
                    ? 'Requested slot clashed with an existing booking'
                    : 'No staff rostered/free for the requested slot')
                    . '; suggested nearest free slot.'
                    . ($sent['dry_run'] ? ' (dry-run)' : ''),
            ]);
            return true;
        }

        $pending = \App\Models\Booking::awaitingMode($leadId);

        // Time AND mode in one message → book and confirm outright.
        if ($mode !== null) {
            $booking = $pending !== null
                ? \App\Pipeline\Booking\BookingCreator::confirm((int) $pending['id'], $mode, $parsed['datetime'], $decision['model'])
                : \App\Pipeline\Booking\BookingCreator::create(
                    $leadId,
                    $roomId,
                    $parsed['datetime'],
                    $decision['model'],
                    'Auto-booked from conversation. Time parse: ' . $parsed['reasoning'],
                    $mode
                );
            \App\Pipeline\Booking\ConfirmationSender::send($booking, $decision['model'], $this->wa);
            return true;
        }

        // Time known, mode not — hold the exact slot and ask the mode question.
        if ($pending !== null) {
            // The slot moved, so the agent held for the old time moves with it.
            \App\Models\Booking::update((int) $pending['id'], [
                'viewing_datetime' => $parsed['datetime'],
                'staff_id'         => \App\Pipeline\Booking\StaffScheduler::assign(
                    $parsed['datetime'],
                    null,
                    (int) $pending['id']
                ),
            ]);
        } else {
            \App\Pipeline\Booking\BookingCreator::create(
                $leadId,
                $roomId,
                $parsed['datetime'],
                $decision['model'],
                'Slot proposed from conversation. Time parse: ' . $parsed['reasoning'],
                null,
                'pending'
            );
        }

        $friendly = date('l, j M \a\t g:ia', strtotime($parsed['datetime']));
        $reply = ($parsed['confident']
                ? "📅 $friendly it is — the slot's free."
                : "📅 How about $friendly? That slot's free.")
            . " One last thing: online video call, or face-to-face at the property? Just reply \"video call\" or \"in person\".";
        $sent = $this->wa->sendText($lead['wa_phone'], $reply);

        EpisodicLogger::log([
            'lead_id'      => $leadId,
            'phase'        => 'conversion',
            'skill'        => 'automate',
            'model_used'   => $decision['model'],
            'direction'    => 'outbound',
            'message_out'  => $reply,
            'message_kind' => 'question',
            'reasoning'    => 'Proposed exact viewing slot; awaiting viewing-mode choice (video call vs in person).'
                . ($sent['dry_run'] ? ' (dry-run)' : ''),
        ]);

        return true;
    }

    /**
     * Handles the answer to "video call or in person?": confirms the pending
     * slot with the chosen mode and sends the receipt — no model calls.
     */
    private function handleModeReply(array $lead, string $text): bool
    {
        $leadId = (int) $lead['id'];

        $pending = \App\Models\Booking::awaitingMode($leadId);
        if ($pending === null) {
            return false;
        }

        $mode = \App\Pipeline\Booking\ViewingMode::detect($text);
        if ($mode === null) {
            return false; // not a mode answer — let the full pipeline handle it
        }

        EpisodicLogger::log([
            'lead_id'    => $leadId,
            'phase'      => 'conversion',
            'skill'      => 'understand',
            'model_used' => 'viewing-mode-detector',
            'direction'  => 'inbound',
            'message_in' => $text,
            'intent'     => 'booking_request',
            'reasoning'  => "Viewing-mode choice detected ($mode) for pending booking #{$pending['id']} — no model calls needed.",
        ]);

        $booking = \App\Pipeline\Booking\BookingCreator::confirm((int) $pending['id'], $mode, null, 'viewing-mode-detector');
        \App\Pipeline\Booking\ConfirmationSender::send($booking, 'viewing-mode-detector', $this->wa);

        return true;
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
