<?php

declare(strict_types=1);

namespace App\AI\Memory;

use App\Models\Interaction;

/**
 * The proposal's "Memory" claim, implemented: every customer has a unique
 * lead ID; on return contact Eve recalls their name and prior enquiry and
 * references it directly (e.g. "Still looking for that budget room in
 * Setapak, or has your search changed?") — never a generic "hi again".
 *
 * Reads the lead's own ai_interactions history — per-customer episodic
 * memory, distinct from the general learned-rule store (ai_learned_memory).
 */
final class LeadMemoryProfile
{
    /** Hours of silence after which a new inbound message counts as a return visit. */
    private const RETURN_GAP_HOURS = 6;

    /**
     * A recall line for CreateSkill to open with — or null when this is a
     * first contact / an ongoing conversation that needs no recall.
     */
    public static function recallLine(array $lead): ?string
    {
        if (empty($lead['is_returning'])) {
            return null;
        }

        $last = Interaction::forLead((int) $lead['id'], 1)[0] ?? null;
        if ($last === null) {
            return null; // returning number but no logged history — nothing to recall
        }

        // Measured on the database clock — see Interaction::minutesSinceLast().
        $gapMinutes = Interaction::minutesSinceLast((int) $lead['id']);
        if ($gapMinutes === null || $gapMinutes < self::RETURN_GAP_HOURS * 60) {
            return null; // same ongoing conversation, no recall needed
        }

        $facts = array_filter([
            'name'        => $lead['name'],
            'looked for'  => $lead['room_type'] ? "a {$lead['room_type']} room" : null,
            'in area'     => $lead['location'],
            'budget'      => $lead['budget'] ? 'around RM' . $lead['budget'] : null,
            'moving'      => $lead['move_in_date'],
            'last status' => $lead['status'],
        ]);

        if (count($facts) <= 1) {
            return null; // nothing substantive was ever captured
        }

        $summary = [];
        foreach ($facts as $label => $value) {
            $summary[] = "$label: $value";
        }

        return 'This customer has contacted us before (lead #' . $lead['id'] . ', last contact '
            . $last['created_at'] . '). Prior enquiry — ' . implode('; ', $summary)
            . '. Open by referencing their previous search specifically and asking if it has changed.';
    }
}
