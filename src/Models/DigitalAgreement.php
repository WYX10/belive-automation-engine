<?php

declare(strict_types=1);

namespace App\Models;

/**
 * digital_agreements — generated tenancy agreement text + the typed-name
 * acknowledgement state. access_code is what the tenant uses to open theirs.
 */
final class DigitalAgreement extends BaseModel
{
    protected const TABLE = 'digital_agreements';

    public static function findByAccessCode(string $code): ?array
    {
        return self::first(['access_code' => strtoupper(trim($code))]);
    }

    public static function forLead(int $leadId): array
    {
        return self::all(['lead_id' => $leadId]);
    }
}
