<?php

declare(strict_types=1);

namespace App\Agreements;

use App\AI\Memory\EpisodicLogger;
use App\AI\ModelRouter;
use App\Models\DigitalAgreement;
use App\Models\Lead;
use App\Models\Room;
use RuntimeException;

/**
 * Auto-generates a plain-text tenancy agreement from the room + lead details
 * (real model call, content_creation phase). The tenant acknowledges it via a
 * typed-name + checkbox flow — an ACKNOWLEDGEMENT, not a legally binding
 * cryptographic e-signature (stated openly in the demo script).
 */
final class DigitalAgreementGenerator
{
    private const SYSTEM = <<<PROMPT
You draft a plain-language room tenancy agreement for BeLive (Malaysia). Clear sentence-case English a student can read, no legalese walls.

Structure (plain text, numbered sections): parties, the room, monthly rental and what it includes, deposit terms (BeLive standard: zero deposit), house rules, cleaning service, notice period (30 days), and a final line stating this document is acknowledged digitally.

Use ONLY the details provided — never invent names, prices, or dates. Return only the agreement text.
PROMPT;

    /** @return array the created digital_agreements row */
    public static function generate(int $leadId, int $roomId): array
    {
        $lead = Lead::find($leadId) ?? throw new RuntimeException('Lead not found.');
        $room = Room::find($roomId) ?? throw new RuntimeException('Room not found.');

        // Tenure: the customer's indicated preference, else 12-month best value.
        $tenure = in_array($lead['preferred_tenure'] ?? '', Room::TENURES, true) ? $lead['preferred_tenure'] : '12_month';

        $details = [
            'tenant_name'   => $lead['name'] ?: 'Tenant',
            'tenant_phone'  => $lead['wa_phone'],
            'room'          => $room['name'],
            'property'      => $room['property_name'],
            'area'          => $room['location'],
            'room_type'     => $room['room_type'],
            'tenure'        => Room::TENURE_LABELS[$tenure],
            'monthly_rm'    => \App\Catalog\PricingCalculator::priceFor($roomId, $tenure),
            'deposit_rm'    => (float) ($room['deposit_amount'] ?? 0),
            'features'      => Room::amenities($roomId),
            'move_in'       => $lead['move_in_date'] ?: 'to be confirmed',
            'date_today'    => date('j F Y'),
        ];

        $client = ModelRouter::clientForPhase('content_creation');
        $result = $client->generate(
            self::SYSTEM,
            [['role' => 'user', 'content' => 'DETAILS: ' . json_encode($details, JSON_UNESCAPED_UNICODE)]],
            ['max_tokens' => 1500, 'temperature' => 0.2, 'mock_hint' => 'agreement']
        );

        if (trim($result['text']) === '') {
            throw new RuntimeException('Agreement generation returned empty text.');
        }

        do {
            $code = strtoupper(bin2hex(random_bytes(4)));
        } while (DigitalAgreement::findByAccessCode($code) !== null);

        $id = DigitalAgreement::create([
            'lead_id'            => $leadId,
            'room_id'            => $roomId,
            'agreement_text'     => $result['text'],
            'status'             => 'sent',
            'access_code'        => $code,
            'generated_by_model' => $result['model'],
        ]);

        EpisodicLogger::activity('agreement_generated', 'content_creation', $result['model'], $leadId, "agreement #$id for room #$roomId");

        return DigitalAgreement::find($id);
    }
}
