<?php

declare(strict_types=1);

namespace App\Models;

use App\AI\Memory\EpisodicLogger;
use App\Core\Database;
use RuntimeException;
use Throwable;

/**
 * Referral-point wallet and rent-credit redemption ledger.
 *
 * Earned points remain sourced from credited referral rows. Requested and
 * approved redemptions reserve points immediately; rejected requests refund
 * them automatically because they are excluded from the spent total.
 */
final class ReferralRedemption extends BaseModel
{
    protected const TABLE = 'referral_redemptions';

    /** @return array<int, array<string, mixed>> */
    public static function forLead(int $leadId): array
    {
        return self::all(['lead_id' => $leadId], 'requested_at DESC, id DESC');
    }

    /**
     * @return array{earned_points: int, spent_points: int, available_points: int, confirmed_referrals: int}
     */
    public static function walletForLead(int $leadId): array
    {
        $earned = Database::run(
            "SELECT COALESCE(SUM(reward_points), 0) FROM referrals
             WHERE referring_lead_id = ? AND reward_status = 'credited'",
            [$leadId]
        )->fetchColumn();
        $confirmed = Database::run(
            "SELECT COUNT(*) FROM referrals
             WHERE referring_lead_id = ? AND reward_status = 'credited'",
            [$leadId]
        )->fetchColumn();
        $spent = Database::run(
            "SELECT COALESCE(SUM(points_spent), 0) FROM referral_redemptions
             WHERE lead_id = ? AND status IN ('requested', 'approved', 'applied')",
            [$leadId]
        )->fetchColumn();

        $earned = (int) $earned;
        $spent = (int) $spent;

        return [
            'earned_points' => $earned,
            'spent_points' => $spent,
            'available_points' => max(0, $earned - $spent),
            'confirmed_referrals' => (int) $confirmed,
        ];
    }

    /** Reserve points and create one auditable rent-credit request atomically. */
    public static function requestRentCredit(int $leadId): array
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            // Lock both sides of the wallet before calculating the balance so
            // simultaneous submits cannot overspend the same referral points.
            Database::run(
                'SELECT id FROM referrals WHERE referring_lead_id = ? FOR UPDATE',
                [$leadId]
            )->fetchAll();
            Database::run(
                'SELECT id FROM referral_redemptions WHERE lead_id = ? FOR UPDATE',
                [$leadId]
            )->fetchAll();

            $active = (int) Database::run(
                "SELECT COUNT(*) FROM referral_redemptions
                 WHERE lead_id = ? AND status IN ('requested', 'approved')",
                [$leadId]
            )->fetchColumn();
            if ($active > 0) {
                throw new RuntimeException('Your existing rent credit request is still under review.');
            }

            $wallet = self::walletForLead($leadId);
            if ($wallet['available_points'] < RENT_REWARD_POINTS) {
                $needed = RENT_REWARD_POINTS - $wallet['available_points'];
                throw new RuntimeException("You need $needed more points before you can redeem this rent credit.");
            }

            $id = self::create([
                'lead_id' => $leadId,
                'reward_type' => 'rent_credit',
                'points_spent' => RENT_REWARD_POINTS,
                'rent_credit_amount' => RENT_REWARD_CREDIT_RM,
                'status' => 'requested',
            ]);

            EpisodicLogger::activity(
                'rent_reward_requested',
                'lead_gen',
                null,
                $leadId,
                sprintf('%d points reserved for RM%d rent credit request #%d', RENT_REWARD_POINTS, RENT_REWARD_CREDIT_RM, $id)
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return self::find($id) ?? throw new RuntimeException('Rent credit request could not be loaded.');
    }
}
