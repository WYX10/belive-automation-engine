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
     * @return array{sent:bool, dry_run:bool, delivered:bool, message_kinds:string[]}
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

        // 1. Photos go out when the decision says so — either as the pre-price
        //    opener (the Setapak sequencing lesson) or because the customer
        //    asked to see the room. The reply text promising photos counts too:
        //    a message that says "sending photos now" with nothing attached is
        //    the worst of both worlds, so the promise is honoured here rather
        //    than left to whether one boolean survived the model round-trip.
        $promised = (bool) preg_match(SkillSupport::PHOTO_PROMISE, $replyText);
        $wantsPhotos = ($decision['send_photos_first'] ?? false)
            || ($decision['send_photos'] ?? false)
            || $promised;

        if ($wantsPhotos) {
            $reason = match (true) {
                ($decision['send_photos_first'] ?? false) => 'Photos sent before pricing, per decision/learned sequencing rule.',
                ($decision['send_photos'] ?? false)       => 'Photos sent because the customer asked to see the room.',
                default                                   => 'Reply promised photos, so the photos were sent with it.',
            };

            $photoRooms = self::photoRooms($decision);
            if ($photoRooms === []) {
                EpisodicLogger::activity(
                    'photos_unavailable',
                    $phase,
                    $decision['model'],
                    $leadId,
                    'Photos were due but the decision carried no room to show — nothing was sent.'
                );
            }

            foreach (array_slice($photoRooms, 0, 1) as $room) {
                $photos = \App\Models\Room::photoUrls((int) $room['id']);

                if ($photos === []) {
                    // Never let the reply claim something the inventory cannot
                    // back. Surfaced as an activity so the admin sees which room
                    // is missing photos rather than a silent no-op.
                    EpisodicLogger::activity(
                        'photos_unavailable',
                        $phase,
                        $decision['model'],
                        $leadId,
                        "Photos were due for room #{$room['id']} ({$room['name']}) but it has none uploaded."
                    );
                    if ($promised) {
                        $replyText = rtrim($replyText)
                            . "\n\nI don't have photos of that one on hand — I'll get them to you shortly.";
                    }
                    continue;
                }

                foreach (array_slice($photos, 0, 3) as $photoUrl) {
                    $sent = $wa->sendImage($lead['wa_phone'], $photoUrl, $room['name'] . ' — ' . $room['location']);
                    $dryRun = $dryRun || $sent['dry_run'];
                }

                $kinds[] = 'photos';
                EpisodicLogger::log([
                    'lead_id'      => $leadId,
                    'phase'        => $phase,
                    'skill'        => 'automate',
                    'model_used'   => $decision['model'],
                    'direction'    => 'outbound',
                    'message_out'  => '[' . min(3, count($photos)) . " room photos: {$room['name']}]",
                    'message_kind' => 'photos',
                    'reasoning'    => $reason,
                    'memory_used'  => $decision['memory_ids'],
                ]);
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
            'reasoning'    => $decision['reasoning'] . WhatsAppClient::deliveryNote($sent),
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

        return [
            'sent'          => true,
            'dry_run'       => $dryRun,
            'delivered'     => empty($sent['failed']),
            'message_kinds' => $kinds,
        ];
    }

    /**
     * The rooms whose photos should go out, recommended pick first. Sending the
     * inventory's first candidate when the decision named a different room is
     * how a customer asking about RM-111 ends up looking at another unit.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function photoRooms(array $decision): array
    {
        $rooms = $decision['rooms'] ?? [];
        $byId = [];
        foreach ($rooms as $room) {
            $byId[(int) $room['id']] = $room;
        }

        // Walk the recommendation order, not the inventory order — the model's
        // best-first ranking is the whole point of recommended_room_ids.
        $picked = [];
        foreach ($decision['recommended_room_ids'] ?? [] as $id) {
            if (isset($byId[(int) $id])) {
                $picked[] = $byId[(int) $id];
            }
        }

        return $picked !== [] ? $picked : $rooms;
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
