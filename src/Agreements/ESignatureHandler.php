<?php

declare(strict_types=1);

namespace App\Agreements;

use Throwable;

/**
 * The tenant's side of the signing step: typed full name + explicit checkbox,
 * with a server-side timestamp. NOT a cryptographic e-signature — an
 * acknowledgement trail, framed exactly that way to judges.
 *
 * The stage rules live in AgreementWorkflow; this is the thin, forgiving
 * wrapper the tenant page posts into, so a tenant never meets an exception page.
 */
final class ESignatureHandler
{
    /** @return array{ok:bool, error?:string} */
    public static function sign(int $agreementId, string $typedName, bool $checkboxTicked, int $expectedVersion): array
    {
        try {
            AgreementWorkflow::tenantSign($agreementId, $typedName, $checkboxTicked, $expectedVersion);

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** The tenant read it and wants something changed before signing. */
    public static function requestChange(int $agreementId, string $note, int $expectedVersion): array
    {
        try {
            AgreementWorkflow::tenantRequestChange($agreementId, $note, $expectedVersion);

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
