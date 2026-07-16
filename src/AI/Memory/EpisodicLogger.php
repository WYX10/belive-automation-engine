<?php

declare(strict_types=1);

namespace App\AI\Memory;

use App\Core\Database;
use App\Models\Interaction;

/**
 * Writes the episodic record: one ai_interactions row per skill call,
 * including the model's stated reasoning (required so the Learning Log and
 * judges can ask "why did it do that"), plus the ai_activity_log audit row
 * naming which model handled the action (non-negotiable build rule).
 */
final class EpisodicLogger
{
    /**
     * @param array{
     *   lead_id?: ?int, phase: string, skill: string, model_used: string,
     *   direction?: string, message_in?: ?string, message_out?: ?string,
     *   message_kind?: ?string, intent?: ?string, entities?: ?array,
     *   reasoning?: ?string, memory_used?: ?array, response_ms?: ?int
     * } $fields
     */
    public static function log(array $fields): int
    {
        $id = Interaction::create([
            'lead_id'      => $fields['lead_id'] ?? null,
            'phase'        => $fields['phase'],
            'skill'        => $fields['skill'],
            'model_used'   => $fields['model_used'],
            'direction'    => $fields['direction'] ?? 'internal',
            'message_in'   => $fields['message_in'] ?? null,
            'message_out'  => $fields['message_out'] ?? null,
            'message_kind' => $fields['message_kind'] ?? null,
            'intent'       => $fields['intent'] ?? null,
            'entities'     => isset($fields['entities']) ? json_encode($fields['entities'], JSON_UNESCAPED_UNICODE) : null,
            'reasoning'    => $fields['reasoning'] ?? null,
            'memory_used'  => isset($fields['memory_used']) ? json_encode($fields['memory_used'], JSON_UNESCAPED_UNICODE) : null,
            'response_ms'  => $fields['response_ms'] ?? null,
        ]);

        self::activity(
            'skill_' . $fields['skill'],
            $fields['phase'],
            $fields['model_used'],
            $fields['lead_id'] ?? null,
            $fields['intent'] ?? ($fields['message_kind'] ?? null)
        );

        return $id;
    }

    /** Bare audit-trail row for non-skill actions (sends, learning events, ...). */
    public static function activity(string $action, ?string $phase = null, ?string $model = null, ?int $leadId = null, ?string $detail = null): void
    {
        Database::run(
            'INSERT INTO ai_activity_log (action, phase, model_used, lead_id, detail) VALUES (?, ?, ?, ?, ?)',
            [$action, $phase, $model, $leadId, $detail]
        );
    }
}
