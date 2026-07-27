<?php

declare(strict_types=1);

namespace App\Agreements;

use App\AI\Memory\EpisodicLogger;
use App\AI\ModelRouter;
use App\Models\AgreementEvent;
use App\Models\DigitalAgreement;
use App\Models\Lead;
use App\Models\Room;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Drafts the tenancy agreement from the room + tenant details (real model call,
 * content_creation phase) once the tenant has confirmed the room.
 *
 * The draft follows the clause order of a standard Malaysian residential
 * tenancy agreement — parties, premises, term, rent, deposits, utilities, the
 * two sets of covenants, access, termination, default, stamping, PDPA,
 * governing law — written in plain English a student can actually read.
 *
 * The landlord's own details are NOT invented here. Wherever they belong the
 * model writes a {{TOKEN}}, which the owner fills in for real when the document
 * reaches them (see OwnerParticulars and AgreementRenderer). A model must never
 * guess an NRIC or a bank account number.
 */
final class DigitalAgreementGenerator
{
    private const SYSTEM = <<<PROMPT
You draft a residential room tenancy agreement for BeLive (Malaysia), between the property owner (the Landlord) and the tenant.

Follow the clause order of a standard Malaysian residential tenancy agreement, numbered, each with a short heading:
1. Parties — the Landlord ({{LANDLORD_NAME}}, NRIC/passport {{LANDLORD_IC}}, of {{LANDLORD_ADDRESS}}) and the Tenant, named from the details given.
2. The premises — the property, the specific room let, and that the tenant shares the common areas.
3. Term — the exact start and end dates given, and that any renewal needs a fresh written agreement.
4. Rent — the exact monthly amount given, payable in advance on or before the 1st of each month, by transfer to {{BANK_ACCOUNT_NAME}}, {{BANK_NAME}}, account {{BANK_ACCOUNT_NO}}, and what happens if rent is late.
5. Deposits — state the deposit figure given exactly as given. BeLive's standard is zero deposit; never invent a deposit that was not provided.
6. Utilities and services — electricity is billed to the tenant on that room's own submeter reading, and list what the rent already includes.
7. Tenant's covenants — pay rent on time, keep the room clean and in good repair, no illegal or immoral use, no subletting or assignment without written consent, no structural alteration, follow the house rules, quiet hours, no smoking indoors.
8. Landlord's covenants — quiet enjoyment, structural and major repairs, insuring the building, paying quit rent and assessment.
9. Access and inspection — the landlord or BeLive may inspect with at least 24 hours' notice, except in an emergency.
10. Termination and notice — 30 days' written notice by either side, what early termination costs, and what happens to belongings left behind.
11. Default — what counts as a breach and what the landlord may do about it.
12. Stamping — the agreement is to be stamped under the Stamp Act 1949 and who bears the stamp duty.
13. Personal data — both parties consent to their details being processed for administering this tenancy under the Personal Data Protection Act 2010.
14. Governing law — the laws of Malaysia.
15. Signing — the agreement is signed digitally by each party typing their own full name, which is recorded with a timestamp.

Hard rules:
- Use ONLY the details provided. Never invent a name, an NRIC, a price, a date, a deposit or an account number.
- Write these placeholders EXACTLY as written, unchanged, wherever the landlord's own details belong: {{LANDLORD_NAME}}, {{LANDLORD_IC}}, {{LANDLORD_ADDRESS}}, {{LANDLORD_EMAIL}}, {{LANDLORD_PHONE}}, {{BANK_NAME}}, {{BANK_ACCOUNT_NAME}}, {{BANK_ACCOUNT_NO}}. The owner fills them in later — do not guess them, do not describe them, do not leave blank lines instead.
- Clear sentence-case English, no walls of legalese, no Latin.
- Return only the agreement text — no preamble, no commentary, no markdown fences.
PROMPT;

    /**
     * Draft an agreement for a tenant who has confirmed a room. Lands at the
     * 'draft' stage: with admin, not yet released to anybody.
     *
     * @return array the created digital_agreements row
     */
    public static function generate(int $leadId, int $roomId, string $startsOn, string $adminName = 'admin'): array
    {
        $lead = Lead::find($leadId) ?? throw new RuntimeException('Lead not found.');
        $room = Room::find($roomId) ?? throw new RuntimeException('Room not found.');

        // Tenure: the customer's indicated preference, else 12-month best value.
        $tenure = in_array($lead['preferred_tenure'] ?? '', Room::TENURES, true) ? $lead['preferred_tenure'] : '12_month';
        [$startsOn, $endsOn] = self::termDates($startsOn, $tenure);

        $monthlyRent = \App\Catalog\PricingCalculator::priceFor($roomId, $tenure);
        if ($monthlyRent === null) {
            throw new RuntimeException(sprintf(
                'This room has no %s price set — add it to the room before drafting an agreement.',
                Room::TENURE_LABELS[$tenure] ?? $tenure
            ));
        }
        $deposit = (float) ($room['deposit_amount'] ?? 0);

        $result = self::draftText(self::details($lead, $room, $tenure, $startsOn, $endsOn, $monthlyRent, $deposit));

        do {
            $code = strtoupper(bin2hex(random_bytes(4)));
        } while (DigitalAgreement::findByAccessCode($code) !== null);

        $id = DigitalAgreement::create([
            'lead_id'            => $leadId,
            'room_id'            => $roomId,
            'tenure'             => $tenure,
            'starts_on'          => $startsOn,
            'ends_on'            => $endsOn,
            'monthly_rent_rm'    => $monthlyRent,
            'deposit_rm'         => $deposit,
            'agreement_text'     => $result['text'],
            'status'             => 'draft',
            'stage_version'      => 1,
            'owner_name'         => $room['owner_name'],
            'access_code'        => $code,
            'generated_by_model' => $result['model'],
        ]);

        AgreementEvent::record($id, 'admin', $adminName, 'generated', null, 'draft', 'Drafted by ' . $result['model']);
        EpisodicLogger::activity('agreement_generated', 'content_creation', $result['model'], $leadId, "agreement #$id for room #$roomId");

        return DigitalAgreement::find($id);
    }

    /**
     * Run the model again over the same facts, replacing the draft body. Only
     * legal while the agreement is still with admin.
     */
    public static function regenerate(int $agreementId, string $adminName, int $expectedVersion): array
    {
        $agreement = DigitalAgreement::withContext($agreementId) ?? throw new RuntimeException('Agreement not found.');
        $lead = Lead::find((int) $agreement['lead_id']) ?? throw new RuntimeException('Lead not found.');
        $room = $agreement['room_id'] !== null ? Room::find((int) $agreement['room_id']) : null;
        if ($room === null) {
            throw new RuntimeException('The room this agreement was drafted for no longer exists.');
        }
        // Agreements drafted before the workflow existed carry no structured
        // tenure or rent, and a rewrite must not invent either. Cancel it and
        // draft a fresh one from the queue instead.
        if ($agreement['monthly_rent_rm'] === null || $agreement['tenure'] === null) {
            throw new RuntimeException('This agreement predates the signing workflow and has no rent or tenure on record. Cancel it and draft a fresh one from the queue.');
        }

        $result = self::draftText(self::details(
            $lead,
            $room,
            (string) $agreement['tenure'],
            (string) $agreement['starts_on'],
            (string) $agreement['ends_on'],
            (float) $agreement['monthly_rent_rm'],
            (float) $agreement['deposit_rm']
        ));

        return AgreementWorkflow::applyDraftText(
            $agreementId,
            $result['text'],
            $adminName,
            $expectedVersion,
            'regenerated',
            ['generated_by_model' => $result['model']]
        );
    }

    /** @return array{text: string, model: string} */
    private static function draftText(array $details): array
    {
        $client = ModelRouter::clientForPhase('content_creation');
        $result = $client->generate(
            self::SYSTEM,
            [['role' => 'user', 'content' => 'DETAILS: ' . json_encode($details, JSON_UNESCAPED_UNICODE)]],
            ['max_tokens' => 3000, 'temperature' => 0.2, 'mock_hint' => 'agreement']
        );

        if (trim($result['text']) === '') {
            throw new RuntimeException('Agreement generation returned empty text.');
        }

        return ['text' => trim($result['text']), 'model' => $result['model']];
    }

    /** The only facts the model is allowed to write from. */
    private static function details(array $lead, array $room, string $tenure, string $startsOn, string $endsOn, float $monthlyRent, float $deposit): array
    {
        return [
            'tenant_name'   => $lead['name'] ?: 'Tenant',
            'tenant_phone'  => $lead['wa_phone'],
            'room'          => $room['name'],
            'property'      => $room['property_name'],
            'property_address' => $room['address'] ?: $room['location'],
            'area'          => $room['location'],
            'room_type'     => $room['room_type'],
            'tenure'        => Room::TENURE_LABELS[$tenure] ?? $tenure,
            'starts_on'     => $startsOn,
            'ends_on'       => $endsOn,
            'monthly_rm'    => $monthlyRent,
            'deposit_rm'    => $deposit,
            'features'      => Room::amenities((int) $room['id']),
            'move_in'       => $lead['move_in_date'] ?: 'to be confirmed',
            'date_today'    => date('j F Y'),
        ];
    }

    /** @return array{0: string, 1: string} */
    private static function termDates(string $startsOn, string $tenure): array
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startsOn);
        if ($start === false || $start->format('Y-m-d') !== $startsOn) {
            throw new InvalidArgumentException('Choose a valid agreement start date.');
        }

        $months = match ($tenure) {
            'monthly' => 1,
            '6_month' => 6,
            '12_month' => 12,
            default => throw new InvalidArgumentException('Unsupported agreement tenure.'),
        };
        // Anchor to the target calendar month so starts near month-end do not
        // overflow into the following month (for example, 31 Jan + 1 month).
        $targetMonth = $start->modify('first day of this month')->modify("+$months months");
        $startDay = (int) $start->format('d');
        $daysInTargetMonth = (int) $targetMonth->format('t');
        $anniversary = $startDay <= $daysInTargetMonth
            ? $targetMonth->setDate(
                (int) $targetMonth->format('Y'),
                (int) $targetMonth->format('m'),
                $startDay
            )
            : $targetMonth->modify('first day of next month');
        $end = $anniversary->modify('-1 day');

        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }
}
