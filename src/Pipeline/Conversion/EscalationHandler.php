<?php

declare(strict_types=1);

namespace App\Pipeline\Conversion;

use App\AI\Memory\EpisodicLogger;
use App\Core\Database;
use App\Models\Lead;

/**
 * When DecideSkill says a conversation needs a human (complaint, dispute,
 * anything Eve shouldn't answer alone): mark the lead, write the audit trail,
 * and surface it prominently for admin. The customer gets an honest "a human
 * teammate will take over" note rather than an AI bluff.
 */
final class EscalationHandler
{
    public static function escalate(array $lead, string $reason, string $customerMessage): string
    {
        $leadId = (int) $lead['id'];

        Lead::update($leadId, [
            'ai_recommendation' => '⚠ ESCALATED: ' . $reason,
            'notes'             => trim(($lead['notes'] ?? '') . "\n[" . date('Y-m-d H:i') . "] Escalated: $reason"),
        ]);

        EpisodicLogger::activity('escalated_to_human', 'conversion', null, $leadId, $reason);

        // Unprocessed feedback row keeps escalations visible in the learning
        // pipeline too — repeated escalations on the same topic are a signal.
        Database::run(
            'INSERT INTO ai_feedback (lead_id, feedback_source, error_type, comment) VALUES (?, ?, ?, ?)',
            [$leadId, 'pattern_detection', 'missed_intent', "Escalation: $reason — customer said: " . mb_substr($customerMessage, 0, 300)]
        );

        $name = $lead['name'] !== null && $lead['name'] !== '' ? " {$lead['name']}" : '';

        return "Thanks{$name} — this one needs a human teammate, so I've flagged it to the BeLive team. "
            . 'Someone will follow up with you right here shortly.';
    }
}
