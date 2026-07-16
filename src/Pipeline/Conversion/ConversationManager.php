<?php

declare(strict_types=1);

namespace App\Pipeline\Conversion;

use App\AI\Memory\EpisodicLogger;
use App\Integrations\WhatsApp\WhatsAppClient;
use App\Models\Lead;

/**
 * Orchestrates one inbound WhatsApp message through Eve's pipeline.
 *
 * PHASE 3 STATE: hardcoded echo reply — proves the webhook → parse → lead →
 * send → log round-trip end-to-end before any AI logic is layered on top
 * (build-order gate). Phase 4 replaces echo() with the four-skill pipeline.
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

        EpisodicLogger::log([
            'lead_id'    => $leadId,
            'phase'      => 'conversion',
            'skill'      => 'understand',
            'model_used' => 'echo-stub',
            'direction'  => 'inbound',
            'message_in' => $message['text'],
        ]);

        $reply = $this->echo($lead, $message['text']);
        $sent = $this->wa->sendText($message['wa_phone'], $reply);

        EpisodicLogger::log([
            'lead_id'     => $leadId,
            'phase'       => 'conversion',
            'skill'       => 'automate',
            'model_used'  => 'echo-stub',
            'direction'   => 'outbound',
            'message_out' => $reply,
            'reasoning'   => 'Phase 3 echo round-trip test — AI pipeline arrives in Phase 4.'
                . ($sent['dry_run'] ? ' (dry-run: no WhatsApp credential configured)' : ''),
            'response_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ]);
    }

    private function echo(array $lead, string $text): string
    {
        $greeting = !empty($lead['name']) ? "Hi {$lead['name']}! " : 'Hi! ';

        return $greeting . "Eve here (echo test) — you said: \"$text\"";
    }
}
