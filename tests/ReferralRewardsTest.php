<?php

declare(strict_types=1);

/**
 * Proves tenant reward points come from real credited referrals and that rent
 * credit requests reserve/refund those points without double spending.
 */

use App\Models\Lead;
use App\Models\Referral;
use App\Models\ReferralRedemption;

$referrerId = Lead::create([
    'wa_phone' => '601188880001',
    'name' => 'Rewards Test Referrer',
    'source_channel' => 'whatsapp',
]);

$emptyWallet = ReferralRedemption::walletForLead($referrerId);
check('new tenant reward wallet starts at zero', $emptyWallet['available_points'] === 0
    && $emptyWallet['confirmed_referrals'] === 0);

$insufficientBlocked = false;
try {
    ReferralRedemption::requestRentCredit($referrerId);
} catch (RuntimeException $e) {
    $insufficientBlocked = str_contains($e->getMessage(), '200 more points');
}
check('rent credit cannot be requested without enough referral points', $insufficientBlocked);

$codes = [];
for ($i = 1; $i <= 4; $i++) {
    $friendId = Lead::create([
        'wa_phone' => '60118888000' . ($i + 1),
        'name' => "Rewards Test Friend $i",
        'source_channel' => 'referral',
    ]);
    $code = Referral::codeFor($referrerId);
    $codes[] = $code;
    check("referral code $i attaches to one friend", Referral::attachReferredLead($code, $friendId));
    $credited = Referral::creditForReferredLead($friendId);
    check("confirmed referral $i credits fixed points", (int) $credited['reward_points'] === REFERRAL_REWARD_POINTS
        && $credited['reward_status'] === 'credited');
}

check('each referred friend receives a distinct referral code', count(array_unique($codes)) === 4);
$unlockedWallet = ReferralRedemption::walletForLead($referrerId);
check('four confirmed referrals unlock the rent reward', $unlockedWallet['earned_points'] === RENT_REWARD_POINTS
    && $unlockedWallet['available_points'] === RENT_REWARD_POINTS
    && $unlockedWallet['confirmed_referrals'] === 4);

$request = ReferralRedemption::requestRentCredit($referrerId);
check('redeeming creates an auditable requested rent credit', $request['status'] === 'requested'
    && (int) $request['points_spent'] === RENT_REWARD_POINTS
    && (float) $request['rent_credit_amount'] === (float) RENT_REWARD_CREDIT_RM);

$reservedWallet = ReferralRedemption::walletForLead($referrerId);
check('requested rent credit reserves points immediately', $reservedWallet['available_points'] === 0
    && $reservedWallet['spent_points'] === RENT_REWARD_POINTS);

$duplicateBlocked = false;
try {
    ReferralRedemption::requestRentCredit($referrerId);
} catch (RuntimeException $e) {
    $duplicateBlocked = str_contains($e->getMessage(), 'still under review');
}
check('a second active rent credit request is blocked', $duplicateBlocked);

ReferralRedemption::update((int) $request['id'], [
    'status' => 'rejected',
    'review_note' => 'Test rejection returns reserved points.',
]);
$refundedWallet = ReferralRedemption::walletForLead($referrerId);
check('rejected request automatically returns its points', $refundedWallet['available_points'] === RENT_REWARD_POINTS
    && $refundedWallet['spent_points'] === 0);

$retry = ReferralRedemption::requestRentCredit($referrerId);
check('tenant can redeem again after a rejected request', $retry['status'] === 'requested'
    && (int) $retry['id'] !== (int) $request['id']);
