<?php

declare(strict_types=1);

namespace App\Pipeline\Referral;

use App\AI\Memory\EpisodicLogger;
use App\Core\Database;
use App\Models\Referral;

/**
 * Proposal channel #4 — Smart "Refer & Earn". Each lead gets a unique
 * shareable link (/r/{code}); visits are counted and land on the enquiry
 * form with the code attached, so the referred lead is tied back to the
 * referrer for reward crediting on booking.
 */
final class ReferralLinkGenerator
{
    public static function linkFor(int $referringLeadId): string
    {
        $code = Referral::codeFor($referringLeadId);
        $base = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8080', '/');

        return "$base/r/$code";
    }

    /** GET /r/{code} — count the click, forward to the enquiry form. */
    public static function handleVisit(string $code): void
    {
        $code = strtoupper(trim($code));
        $referral = $code !== '' ? Referral::findByCode($code) : null;

        if ($referral === null) {
            http_response_code(404);
            echo 'This referral link is not valid.';
            return;
        }

        Database::run('UPDATE referrals SET clicks = clicks + 1 WHERE id = ?', [$referral['id']]);
        EpisodicLogger::activity('referral_link_visited', 'lead_gen', null, (int) $referral['referring_lead_id'], "code $code");

        header('Location: /enquiry?ref=' . urlencode($code));
    }
}
