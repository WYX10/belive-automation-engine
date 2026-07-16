<?php

declare(strict_types=1);

namespace App\Agreements;

use App\AI\Memory\EpisodicLogger;
use App\Models\DigitalAgreement;

/**
 * Captures the tenant's acknowledgement: typed full name + explicit checkbox,
 * with a server-side timestamp. NOT a cryptographic e-signature — an
 * acknowledgement trail, framed exactly that way to judges.
 */
final class ESignatureHandler
{
    /** @return array{ok:bool, error?:string} */
    public static function acknowledge(int $agreementId, string $typedName, bool $checkboxTicked): array
    {
        $agreement = DigitalAgreement::find($agreementId);
        if ($agreement === null) {
            return ['ok' => false, 'error' => 'Agreement not found.'];
        }
        if ($agreement['status'] === 'acknowledged') {
            return ['ok' => false, 'error' => 'This agreement is already acknowledged.'];
        }

        $typedName = trim($typedName);
        if (mb_strlen($typedName) < 3) {
            return ['ok' => false, 'error' => 'Type your full name as the acknowledgement.'];
        }
        if (!$checkboxTicked) {
            return ['ok' => false, 'error' => 'Tick the confirmation box to acknowledge.'];
        }

        DigitalAgreement::update($agreementId, [
            'status'            => 'acknowledged',
            'acknowledged_name' => $typedName,
            'acknowledged_at'   => date('Y-m-d H:i:s'),
        ]);

        EpisodicLogger::activity('agreement_acknowledged', null, null, (int) $agreement['lead_id'], "agreement #$agreementId by \"$typedName\"");

        return ['ok' => true];
    }
}
