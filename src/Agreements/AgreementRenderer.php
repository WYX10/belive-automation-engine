<?php

declare(strict_types=1);

namespace App\Agreements;

use App\Models\Room;

/**
 * Assembles the document everyone actually reads.
 *
 * The AI writes the body once, with {{TOKENS}} where the landlord's details
 * belong; this class fills those in from the stored particulars and appends two
 * deterministic parts the model is not trusted to produce: the Schedule of
 * particulars and the execution block. So even if a model drops a token, the
 * signed document still carries the full, correct facts.
 *
 * Nothing is cached: the document is rendered from the row every time, and the
 * columns it reads are locked by workflow stage (see AgreementWorkflow), which
 * is what makes a signature mean something.
 */
final class AgreementRenderer
{
    /** Tokens the generator prompt instructs the model to leave for the owner. */
    public const TOKENS = [
        '{{LANDLORD_NAME}}'     => 'name',
        '{{LANDLORD_IC}}'       => 'ic',
        '{{LANDLORD_ADDRESS}}'  => 'address',
        '{{LANDLORD_EMAIL}}'    => 'email',
        '{{LANDLORD_PHONE}}'    => 'phone',
        '{{BANK_NAME}}'         => 'bank',
        '{{BANK_ACCOUNT_NAME}}' => 'account_holder',
        '{{BANK_ACCOUNT_NO}}'   => 'account_no',
    ];

    private const PENDING = '[ to be completed by the owner ]';

    /**
     * @param array $agreement a digital_agreements row, ideally from
     *                         DigitalAgreement::withContext() so the tenant and
     *                         room names are present
     * @param bool  $reveal    false keeps the landlord's NRIC and account number
     *                         masked — for admin previews before release
     */
    public static function render(array $agreement, bool $reveal = true): string
    {
        $filled = OwnerParticulars::complete($agreement);
        $values = !$filled
            ? array_fill_keys(array_values(self::TOKENS), self::PENDING)
            : ($reveal ? OwnerParticulars::reveal($agreement) : OwnerParticulars::masked($agreement));

        $replacements = [];
        foreach (self::TOKENS as $token => $key) {
            $replacements[$token] = ($values[$key] ?? '') !== '' ? $values[$key] : self::PENDING;
        }
        $body = strtr((string) $agreement['agreement_text'], $replacements);

        $parts = [self::heading($agreement), trim($body), self::schedule($agreement, $values, $filled)];

        $extra = trim((string) ($agreement['owner_extra_terms'] ?? ''));
        if ($extra !== '') {
            $parts[] = "ADDITIONAL TERMS AGREED BY THE LANDLORD\n\n" . $extra;
        }

        $parts[] = self::execution($agreement);

        return implode("\n\n", $parts) . "\n";
    }

    /** The one-line summary used in lists and WhatsApp copy. */
    public static function summary(array $agreement): string
    {
        return sprintf(
            'Tenancy of %s from %s to %s at RM %s per month',
            $agreement['room_name'] ?? 'the room',
            self::date($agreement['starts_on'] ?? null),
            self::date($agreement['ends_on'] ?? null),
            number_format((float) ($agreement['monthly_rent_rm'] ?? 0), 2)
        );
    }

    private static function heading(array $agreement): string
    {
        return sprintf(
            "TENANCY AGREEMENT\nBeLive reference: BL-AGR-%05d\nPrepared on: %s",
            (int) $agreement['id'],
            self::date($agreement['created_at'] ?? null)
        );
    }

    /**
     * Schedule A — the facts of the letting in one place, exactly as a
     * Malaysian tenancy agreement carries them.
     *
     * @param array<string, string> $values landlord particulars (or pending markers)
     */
    private static function schedule(array $agreement, array $values, bool $filled): string
    {
        $tenureLabel = Room::TENURE_LABELS[$agreement['tenure'] ?? ''] ?? 'as agreed';
        $rent = $agreement['monthly_rent_rm'] !== null
            ? 'RM ' . number_format((float) $agreement['monthly_rent_rm'], 2) . ' per month'
            : 'as stated above';
        $deposit = (float) ($agreement['deposit_rm'] ?? 0) > 0
            ? 'RM ' . number_format((float) $agreement['deposit_rm'], 2)
            : 'RM 0.00 — BeLive zero-deposit standard';

        $rows = [
            'Landlord'               => $values['name'] ?: self::PENDING,
            'Landlord NRIC/passport' => $values['ic'] ?: self::PENDING,
            'Landlord address'       => $values['address'] ?: self::PENDING,
            'Landlord email'         => $values['email'] ?: self::PENDING,
            'Landlord phone'         => $values['phone'] ?: self::PENDING,
            'Tenant'                 => $agreement['tenant_name'] ?? 'Tenant',
            'Tenant phone'           => $agreement['wa_phone'] ?? '',
            // The room address already names the property on BeLive listings —
            // printing both reads as a stutter on a legal document.
            'Property'               => trim((string) ($agreement['address'] ?? '')) ?: (trim((string) ($agreement['property_name'] ?? '')) ?: 'As described above'),
            'Room'                   => $agreement['room_name'] ?? '',
            'Term'                   => $tenureLabel,
            'Term starts'            => self::date($agreement['starts_on'] ?? null),
            'Term ends'              => self::date($agreement['ends_on'] ?? null),
            'Rent'                   => $rent,
            'Security deposit'       => $deposit,
            'Rent payable to'        => $values['account_holder'] ?: self::PENDING,
            'Bank'                   => $values['bank'] ?: self::PENDING,
            'Account number'         => $values['account_no'] ?: self::PENDING,
        ];

        $width = max(array_map('mb_strlen', array_keys($rows)));
        $lines = [];
        foreach ($rows as $label => $value) {
            if ($value === '') {
                continue;
            }
            $lines[] = '  ' . str_pad($label, $width) . ' : ' . $value;
        }

        $note = $filled
            ? ''
            : "\n\n  The landlord's particulars are completed by the owner before this agreement is released for signing.";

        return "SCHEDULE A — PARTICULARS\n\n" . implode("\n", $lines) . $note;
    }

    private static function execution(array $agreement): string
    {
        $landlord = ($agreement['owner_signed_at'] ?? null) !== null
            ? sprintf(
                "  Signed by the Landlord : %s\n  Date and time          : %s",
                $agreement['owner_signed_name'],
                $agreement['owner_signed_at']
            )
            : "  Signed by the Landlord : awaiting signature\n  Date and time          : —";

        $tenant = ($agreement['acknowledged_at'] ?? null) !== null
            ? sprintf(
                "  Signed by the Tenant   : %s\n  Date and time          : %s",
                $agreement['acknowledged_name'],
                $agreement['acknowledged_at']
            )
            : "  Signed by the Tenant   : awaiting signature\n  Date and time          : —";

        return "EXECUTION\n\n$landlord\n\n$tenant\n\n"
            . "  Both parties sign this agreement digitally: each types their own full name, which is\n"
            . "  recorded with a server timestamp against this document. That is a dated record that\n"
            . "  both sides saw the same terms — it is not a cryptographic e-signature, and it does not\n"
            . "  replace stamping of the agreement under the Stamp Act 1949.";
    }

    private static function date(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $timestamp = strtotime($value);

        return $timestamp === false ? $value : date('j F Y', $timestamp);
    }
}
