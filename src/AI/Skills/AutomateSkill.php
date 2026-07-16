<?php

declare(strict_types=1);

namespace App\AI\Skills;

use App\AI\Memory\EpisodicLogger;
use App\Integrations\WhatsApp\WhatsAppClient;
use App\Models\Lead;

/**
 * Skill 4 — Automates (End-to-end workflow): executes the decided action with
 * zero manual work — sends photos first when the decision (or a learned
 * sequencing rule) says so, sends the reply, updates lead status and the
 * dashboard's AI assessment. Booking handoff happens in ConversationManager
 * when next_action=book_viewing.
 */
final class AutomateSkill
{
    /**
     * @return array{sent:bool, dry_run:bool, message_kinds:string[]}
     */
    public static function run(
        array $lead,
        array $decision,
        string $replyText,
        WhatsAppClient $wa,
        string $phase = 'conversion',
        int $totalMs = 0
    ): array {
        $leadId = (int) $lead['id'];
        $kinds = [];
        $dryRun = false;

        // 1. Photos before price when decided (the Setapak sequencing lesson).
        if ($decision['send_photos_first']) {
            foreach (array_slice($decision['rooms'], 0, 1) as $room) {
                $photos = \App\Models\Room::photoUrls((int) $room['id']);
                foreach (array_slice($photos, 0, 3) as $photoUrl) {
                    $sent = $wa->sendImage($lead['wa_phone'], $photoUrl, $room['name'] . ' — ' . $room['location']);
                    $dryRun = $dryRun || $sent['dry_run'];
                }
                if ($photos !== []) {
                    $kinds[] = 'photos';
                    EpisodicLogger::log([
                        'lead_id'      => $leadId,
                        'phase'        => $phase,
                        'skill'        => 'automate',
                        'model_used'   => $decision['model'],
                        'direction'    => 'outbound',
                        'message_out'  => '[' . count($photos) . " room photos: {$room['name']}]",
                        'message_kind' => 'photos',
                        'reasoning'    => 'Photos sent before pricing, per decision/learned sequencing rule.',
                        'memory_used'  => $decision['memory_ids'],
                    ]);
                }
            }
        }

        // 2. The reply text itself.
        $sent = $wa->sendText($lead['wa_phone'], $replyText);
        $dryRun = $dryRun || $sent['dry_run'];
        $kind = self::classifyKind($replyText, $decision);
        $kinds[] = $kind;

        EpisodicLogger::log([
            'lead_id'      => $leadId,
            'phase'        => $phase,
            'skill'        => 'automate',
            'model_used'   => $decision['model'],
            'direction'    => 'outbound',
            'message_out'  => $replyText,
            'message_kind' => $kind,
            'reasoning'    => $decision['reasoning']
                . ($sent['dry_run'] ? ' (dry-run: no WhatsApp credential configured — logged, not delivered)' : ''),
            'memory_used'  => $decision['memory_ids'],
            'response_ms'  => $totalMs,
        ]);

        // 3. Lead status + dashboard AI assessment.
        if ($decision['qualified'] && $lead['status'] === 'new') {
            Lead::transition($leadId, 'qualified');
        }
        Lead::setAiAssessment(
            $leadId,
            $decision['closing_probability'],
            $decision['lead_signals'],
            $decision['recommendation']
        );

        return ['sent' => true, 'dry_run' => $dryRun, 'message_kinds' => $kinds];
    }

    /**
     * Tag outbound messages by content type — drop-off pattern detection
     * (Phase 5) looks for conversations that die right after 'price_quote'.
     */
    public static function classifyKind(string $text, array $decision): string
    {
        if (preg_match('/\bRM\s?\d{2,5}\b/i', $text)) {
            return 'price_quote';
        }

        return match ($decision['next_action']) {
            'book_viewing' => 'booking_confirmation',
            'request_info' => 'question',
            'escalate'     => 'escalation_notice',
            default        => 'reply',
        };
    }
}
