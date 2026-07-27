<?php

declare(strict_types=1);

namespace App\Agreements;

use App\Core\Encryption;
use InvalidArgumentException;

/**
 * The landlord's own details, collected once per agreement: who they are, where
 * notices go, and the account rent is paid into.
 *
 * NRIC/passport and the bank account number are encrypted at rest — they are
 * the two fields on this form that are worth stealing, and admin list screens
 * have no business decrypting them, so the last four digits are kept in the
 * clear alongside for identification.
 */
final class OwnerParticulars
{
    /** Malaysian banks BeLive owners actually use, plus a free-text fallback. */
    public const BANKS = [
        'Maybank', 'CIMB Bank', 'Public Bank', 'RHB Bank', 'Hong Leong Bank',
        'AmBank', 'Bank Islam', 'Bank Rakyat', 'OCBC Bank', 'HSBC Bank',
        'Standard Chartered', 'UOB Malaysia', 'Affin Bank', 'Alliance Bank', 'Agrobank', 'Other',
    ];

    /**
     * Validate the owner's submission and turn it into the column map to store.
     *
     * @param array<string, string> $input raw $_POST values
     * @return array<string, mixed> digital_agreements columns
     * @throws InvalidArgumentException with a message written for the owner
     */
    public static function fromInput(array $input): array
    {
        $fullName = self::text($input, 'owner_full_name');
        $ic = strtoupper(preg_replace('/\s+/', '', self::text($input, 'owner_ic')));
        $email = trim($input['owner_email'] ?? '');
        $phone = preg_replace('/[^0-9+]/', '', $input['owner_phone'] ?? '');
        $address = self::text($input, 'owner_address');
        $bankName = self::text($input, 'owner_bank_name');
        if ($bankName === 'Other') {
            $bankName = self::text($input, 'owner_bank_other');
        }
        $bankHolder = self::text($input, 'owner_bank_holder');
        $account = preg_replace('/[\s-]/', '', $input['owner_bank_account'] ?? '');
        $extraTerms = trim($input['owner_extra_terms'] ?? '');
        $signature = self::text($input, 'owner_signature');

        if (mb_strlen($fullName) < 3) {
            throw new InvalidArgumentException('Enter your full name exactly as it appears on your NRIC or passport.');
        }
        if (!preg_match('/^[A-Z0-9-]{6,20}$/', $ic)) {
            throw new InvalidArgumentException('Enter a valid NRIC (for example 880101-14-5501) or passport number.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Enter a valid email address — legal notices under the agreement go there.');
        }
        if (!preg_match('/^\+?[0-9]{8,15}$/', $phone)) {
            throw new InvalidArgumentException('Enter a valid contact phone number (8–15 digits).');
        }
        if (mb_strlen($address) < 10) {
            throw new InvalidArgumentException('Enter your full correspondence address, including postcode and state.');
        }
        if (mb_strlen($bankName) < 3 || $bankName === 'Other') {
            throw new InvalidArgumentException('Choose the bank that receives the rent, or type its name.');
        }
        if (mb_strlen($bankHolder) < 3) {
            throw new InvalidArgumentException('Enter the account holder name exactly as registered with the bank.');
        }
        if (!preg_match('/^[0-9]{6,20}$/', $account)) {
            throw new InvalidArgumentException('Enter a valid bank account number (6–20 digits).');
        }
        if (mb_strlen($extraTerms) > 1500) {
            throw new InvalidArgumentException('Keep additional terms under 1500 characters.');
        }
        // The signature is the landlord's own declared name typed back — the one
        // integrity check available without a certificate authority.
        if (self::normalise($signature) !== self::normalise($fullName)) {
            throw new InvalidArgumentException('Type your full name exactly as entered above to sign.');
        }

        return [
            'owner_full_name'          => $fullName,
            'owner_ic_enc'             => Encryption::encrypt($ic),
            'owner_ic_last4'           => mb_substr($ic, -4),
            'owner_email'              => $email,
            'owner_phone'              => $phone,
            'owner_address'            => $address,
            'owner_bank_name'          => $bankName,
            'owner_bank_holder'        => $bankHolder,
            'owner_bank_account_enc'   => Encryption::encrypt($account),
            'owner_bank_account_last4' => mb_substr($account, -4),
            'owner_extra_terms'        => $extraTerms !== '' ? $extraTerms : null,
            'owner_signed_name'        => $fullName,
            'owner_signed_at'          => date('Y-m-d H:i:s'),
        ];
    }

    /** True once the landlord has supplied and signed their particulars. */
    public static function complete(array $agreement): bool
    {
        return ($agreement['owner_full_name'] ?? null) !== null
            && ($agreement['owner_bank_account_enc'] ?? null) !== null
            && ($agreement['owner_signed_at'] ?? null) !== null;
    }

    /**
     * The values as they belong in the signed document — decrypted.
     *
     * @return array<string, string> token name => value
     */
    public static function reveal(array $agreement): array
    {
        return [
            'name'           => (string) ($agreement['owner_full_name'] ?? ''),
            'ic'             => self::decryptOr($agreement['owner_ic_enc'] ?? null, 'unavailable'),
            'email'          => (string) ($agreement['owner_email'] ?? ''),
            'phone'          => (string) ($agreement['owner_phone'] ?? ''),
            'address'        => (string) ($agreement['owner_address'] ?? ''),
            'bank'           => (string) ($agreement['owner_bank_name'] ?? ''),
            'account_holder' => (string) ($agreement['owner_bank_holder'] ?? ''),
            'account_no'     => self::decryptOr($agreement['owner_bank_account_enc'] ?? null, 'unavailable'),
            'extra_terms'    => (string) ($agreement['owner_extra_terms'] ?? ''),
        ];
    }

    /**
     * The same values for screens that only need to recognise the record —
     * admin queues and the owner's own read-back. Never decrypts.
     *
     * @return array<string, string>
     */
    public static function masked(array $agreement): array
    {
        return [
            'name'           => (string) ($agreement['owner_full_name'] ?? ''),
            'ic'             => self::mask($agreement['owner_ic_last4'] ?? null),
            'email'          => (string) ($agreement['owner_email'] ?? ''),
            'phone'          => (string) ($agreement['owner_phone'] ?? ''),
            'address'        => (string) ($agreement['owner_address'] ?? ''),
            'bank'           => (string) ($agreement['owner_bank_name'] ?? ''),
            'account_holder' => (string) ($agreement['owner_bank_holder'] ?? ''),
            'account_no'     => self::mask($agreement['owner_bank_account_last4'] ?? null),
            'extra_terms'    => (string) ($agreement['owner_extra_terms'] ?? ''),
        ];
    }

    private static function mask(?string $last4): string
    {
        return $last4 === null || $last4 === '' ? '—' : '•••• ' . $last4;
    }

    /**
     * A wrong or rotated APP_ENCRYPTION_KEY must not take the whole document
     * down — the rest of the agreement still renders, with the field named as
     * unreadable rather than silently blank.
     */
    private static function decryptOr(?string $blob, string $fallback): string
    {
        if ($blob === null || $blob === '') {
            return '';
        }
        try {
            return Encryption::decrypt($blob);
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private static function text(array $input, string $key): string
    {
        return trim(preg_replace('/\s+/', ' ', (string) ($input[$key] ?? '')));
    }

    private static function normalise(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value)));
    }
}
