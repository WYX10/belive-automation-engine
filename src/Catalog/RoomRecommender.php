<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Models\Room;

/**
 * AI-assisted room matching over LIVE inventory, called by DecideSkill.
 * Gathers ranked candidates (the room a website visitor enquired about always
 * leads), each with all three tenure prices, and hands DecideSkill the
 * grounding block it reasons over — the model, not this class, decides which
 * room and which tenure fit the person ("Decides — Situational AI"). Never a
 * hardcoded lookup.
 */
final class RoomRecommender
{
    /**
     * @param array $lead          leads row (may carry enquired_room_id / preferred_tenure)
     * @param array $understanding UnderstandSkill output (entities incl. tenure hint)
     *
     * @return array{rooms: array, block: string}
     */
    public static function candidates(array $lead, array $understanding): array
    {
        $entities = $understanding['entities'] ?? [];

        $location = $entities['location'] ?? $lead['location'] ?? null;
        $budget = $entities['budget'] ?? (is_numeric($lead['budget'] ?? null) ? (int) $lead['budget'] : null);
        $roomType = $entities['room_type'] ?? $lead['room_type'] ?? null;

        // Budget filters at the tenure the customer is leaning toward, else at
        // the cheapest tenure so commitment-affordable rooms aren't hidden.
        $tenure = $entities['tenure'] ?? $lead['preferred_tenure'] ?? '12_month';

        $rooms = Room::matches($location, $budget !== null ? (int) $budget : null, $roomType, (string) $tenure);

        // The exact room a website visitor enquired about always leads the list.
        if (!empty($lead['enquired_room_id'])) {
            $enquired = Room::find((int) $lead['enquired_room_id']);
            if ($enquired !== null) {
                $rooms = array_values(array_filter($rooms, fn ($r) => (int) $r['id'] !== (int) $enquired['id']));
                array_unshift($rooms, $enquired + ['is_enquired_room' => true]);
                $rooms = array_slice($rooms, 0, 3);
            }
        }

        $block = "LIVE ROOM INVENTORY (ranked candidates):\n" . Room::promptBlock($rooms);

        if (!empty($lead['enquired_room_id'])) {
            $block .= "\nNote: the FIRST room is the one this customer enquired about on the website — address it directly.";
        }
        if (!empty($lead['preferred_tenure'])) {
            $block .= "\nCustomer's indicated tenure so far: " . Room::TENURE_LABELS[$lead['preferred_tenure']] . '.';
        }

        return ['rooms' => $rooms, 'block' => $block];
    }
}
