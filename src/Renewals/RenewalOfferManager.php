<?php

declare(strict_types=1);

namespace App\Renewals;

use App\Core\Database;
use App\Models\DigitalAgreement;
use App\Models\RenewalOffer;
use App\Models\Room;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The rules behind a renewal offer: who may make one, when, at what price, and
 * who may answer it.
 *
 * The window is the last DigitalAgreement::ENDING_SOON_DAYS of a tenancy —
 * the same threshold that turns the tenant's countdown orange. Earlier than
 * that a "your term is nearly up" discount is not an offer, it is a price cut;
 * later than the end date the tenancy is over and a new listing is the honest
 * route.
 *
 * A promo must be BELOW what the tenant pays now. An offer that raises the rent
 * is a renewal quote, not a promotion, and dressing one up as the other in the
 * tenant's portal would be the opposite of what this feature is for.
 *
 * Accepting changes nothing legal. It records that the tenant wants to stay at
 * that price; BeLive then drafts the renewal agreement through the usual
 * admin -> owner -> tenant round trip. Nothing here touches digital_agreements.
 */
final class RenewalOfferManager
{
    /** Term length per tenure, for working out when the new term would end. */
    private const TENURE_MONTHS = ['monthly' => 1, '6_month' => 6, '12_month' => 12];

    /** Tenancy states in which an owner may offer a renewal price. */
    private const OFFERABLE_STATES = ['ending_soon', 'ending_today'];

    /**
     * Make an offer on a tenancy the owner owns.
     *
     * Input: tenure, promo_rent_rm, optionally message and expires_on.
     *
     * @param array<string, mixed> $input
     */
    public static function offer(string $ownerName, int $agreementId, array $input): array
    {
        $tenure = (string) ($input['tenure'] ?? '');
        if (!in_array($tenure, Room::TENURES, true)) {
            throw new InvalidArgumentException('Choose a valid renewal length.');
        }

        $promo = $input['promo_rent_rm'] ?? null;
        if (!is_numeric($promo) || (float) $promo <= 0) {
            throw new InvalidArgumentException('The renewal price must be more than RM 0.00.');
        }
        $promo = round((float) $promo, 2);

        $message = trim((string) ($input['message'] ?? ''));
        if (mb_strlen($message) > 500) {
            throw new InvalidArgumentException('Your message must be 500 characters or fewer.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $agreement = Database::run(
                'SELECT * FROM digital_agreements WHERE id = ? FOR UPDATE',
                [$agreementId]
            )->fetch();
            if (!$agreement || $agreement['owner_name'] !== $ownerName) {
                throw new RuntimeException('That tenancy is not on your account.');
            }
            if ($agreement['status'] !== 'completed') {
                throw new RuntimeException('This agreement is not signed by both parties yet, so there is no term to renew.');
            }

            $timeline = DigitalAgreement::timeline($agreement);
            if ($timeline === null) {
                throw new RuntimeException('This agreement has no confirmed start and end date to renew from.');
            }
            if (!in_array($timeline['state'], self::OFFERABLE_STATES, true)) {
                throw new RuntimeException(sprintf(
                    'A renewal price can only be offered in the last %d days of a tenancy.',
                    DigitalAgreement::ENDING_SOON_DAYS
                ));
            }

            $currentRent = self::currentRent($agreement);
            if ($currentRent <= 0) {
                throw new RuntimeException('This tenancy has no recorded rent to discount from.');
            }
            if ($promo >= $currentRent) {
                throw new InvalidArgumentException(sprintf(
                    'A promotional price must be below the RM %s the tenant pays now.',
                    number_format($currentRent, 2)
                ));
            }

            $expiresOn = self::expiryDate($input['expires_on'] ?? null, (string) $agreement['ends_on']);

            $open = Database::run(
                "SELECT id FROM renewal_offers
                 WHERE agreement_id = ? AND status = 'offered' AND expires_on >= CURDATE()
                 FOR UPDATE",
                [$agreementId]
            )->fetch();
            if ($open !== false) {
                throw new RuntimeException('This tenant already has an offer waiting for an answer. Withdraw it first to send a different price.');
            }

            [$startsOn, $endsOn] = self::newTerm((string) $agreement['ends_on'], $tenure);

            $offerId = RenewalOffer::create([
                'agreement_id'    => $agreementId,
                'lead_id'         => (int) $agreement['lead_id'],
                'room_id'         => $agreement['room_id'] !== null ? (int) $agreement['room_id'] : null,
                'owner_name'      => $ownerName,
                'tenure'          => $tenure,
                'current_rent_rm' => $currentRent,
                'promo_rent_rm'   => $promo,
                'starts_on'       => $startsOn,
                'ends_on'         => $endsOn,
                'message'         => $message !== '' ? $message : null,
                'expires_on'      => $expiresOn,
                'status'          => 'offered',
            ]);

            $offer = RenewalOffer::find($offerId) ?? throw new RuntimeException('The offer could not be loaded.');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $offer;
    }

    /** Take back an offer the tenant has not answered yet. */
    public static function withdraw(string $ownerName, int $offerId): array
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $offer = Database::run(
                'SELECT * FROM renewal_offers WHERE id = ? FOR UPDATE',
                [$offerId]
            )->fetch();
            if (!$offer || $offer['owner_name'] !== $ownerName) {
                throw new RuntimeException('That offer is not on your account.');
            }
            if (!RenewalOffer::isOpen($offer)) {
                throw new RuntimeException('Only an offer still waiting for an answer can be withdrawn.');
            }

            RenewalOffer::update($offerId, [
                'status' => 'withdrawn',
                'responded_at' => date('Y-m-d H:i:s'),
            ]);
            $withdrawn = RenewalOffer::find($offerId) ?? throw new RuntimeException('The offer could not be loaded.');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $withdrawn;
    }

    /**
     * The tenant's answer. Scoped by lead_id: an offer can only be answered by
     * the person it was made to.
     */
    public static function respond(int $leadId, int $offerId, string $decision, ?string $note = null): array
    {
        if (!in_array($decision, RENEWAL_OFFER_DECISIONS, true)) {
            throw new InvalidArgumentException('Choose whether to accept or decline.');
        }
        $note = trim((string) ($note ?? ''));
        if (mb_strlen($note) > 500) {
            throw new InvalidArgumentException('Your note must be 500 characters or fewer.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $offer = Database::run(
                'SELECT * FROM renewal_offers WHERE id = ? FOR UPDATE',
                [$offerId]
            )->fetch();
            if (!$offer || (int) $offer['lead_id'] !== $leadId) {
                throw new RuntimeException('That offer was not made to you.');
            }
            if ($offer['status'] !== 'offered') {
                throw new RuntimeException('This offer has already been answered.');
            }
            if (!RenewalOffer::isOpen($offer)) {
                throw new RuntimeException('This offer has expired. Ask your owner to send a new one.');
            }

            RenewalOffer::update($offerId, [
                'status' => $decision,
                'responded_at' => date('Y-m-d H:i:s'),
                'response_note' => $note !== '' ? $note : null,
            ]);
            $answered = RenewalOffer::find($offerId) ?? throw new RuntimeException('The offer could not be loaded.');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $answered;
    }

    /**
     * What the tenant pays today: the rent snapshotted onto the agreement when
     * it was signed, falling back to the room's price at the agreed tenure for
     * agreements written before that column existed.
     */
    public static function currentRent(array $agreement): float
    {
        if (($agreement['monthly_rent_rm'] ?? null) !== null && (float) $agreement['monthly_rent_rm'] > 0) {
            return round((float) $agreement['monthly_rent_rm'], 2);
        }

        $roomId = (int) ($agreement['room_id'] ?? 0);
        $tenure = (string) ($agreement['tenure'] ?? '');
        if ($roomId === 0) {
            return 0.0;
        }

        $prices = Room::prices($roomId);
        if (isset($prices[$tenure])) {
            return round($prices[$tenure]['price'], 2);
        }

        return isset($prices['monthly']) ? round($prices['monthly']['price'], 2) : 0.0;
    }

    /**
     * Whether this tenancy can be offered a price right now, for the owner UI.
     * Same rule the write path enforces, so the button and the outcome agree.
     */
    public static function isOfferable(?array $timeline): bool
    {
        return $timeline !== null && in_array($timeline['state'], self::OFFERABLE_STATES, true);
    }

    /**
     * The term an accepted offer would run for: it starts the day after the
     * current one ends, so there is never a gap or an overlap.
     *
     * @return array{0: string, 1: string} starts_on, ends_on
     */
    public static function newTerm(string $currentEndsOn, string $tenure): array
    {
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $currentEndsOn);
        if ($end === false) {
            throw new RuntimeException('This agreement has no readable end date.');
        }

        $start = $end->modify('+1 day');
        $months = self::TENURE_MONTHS[$tenure] ?? 1;

        return [
            $start->format('Y-m-d'),
            $start->modify("+$months month")->modify('-1 day')->format('Y-m-d'),
        ];
    }

    /**
     * When the offer lapses. Default and ceiling are the tenancy's own end date
     * — an offer that outlives the term it renews would leave the tenant
     * accepting a start date that has already passed.
     */
    private static function expiryDate(mixed $value, string $tenancyEndsOn): string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return $tenancyEndsOn;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($date === false || $date->format('Y-m-d') !== $raw) {
            throw new InvalidArgumentException('Choose a valid date for the offer to expire.');
        }
        if ($raw < date('Y-m-d')) {
            throw new InvalidArgumentException('The offer cannot expire in the past.');
        }
        if ($raw > $tenancyEndsOn) {
            throw new InvalidArgumentException('The offer must expire on or before the tenancy ends, ' . $tenancyEndsOn . '.');
        }

        return $raw;
    }
}
