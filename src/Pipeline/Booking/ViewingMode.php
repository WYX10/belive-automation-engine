<?php

declare(strict_types=1);

namespace App\Pipeline\Booking;

/**
 * Intelligent booking, part 4: the viewing-mode choice. Every proposed slot
 * comes with the question "online video call, or face-to-face at the
 * property?" — this class detects the customer's answer (English / Malay /
 * Chinese keywords) so the confirmation can be sent without a model call.
 */
final class ViewingMode
{
    public const VIDEO_CALL = 'video_call';
    public const IN_PERSON = 'in_person';

    private const VIDEO_PATTERN = '/\b(video ?-?call|video|online|virtual(ly)?|zoom|google ?meet|gmeet|meet link|panggilan video)\b|视频|線上|线上/iu';
    private const IN_PERSON_PATTERN = '/\b(in ?-?person|face ?-? ?to ?-? ?face|f2f|physical(ly)?|on ?-?site|walk ?-?in|come (over|by|visit|see)|visit (the )?(property|room|unit|place)|attend|datang|lawat|melawat)\b|实地|實地|现场|現場|亲自|親自/iu';

    /** null = no clear choice (or both mentioned — ambiguous, keep asking). */
    public static function detect(string $text): ?string
    {
        $video = (bool) preg_match(self::VIDEO_PATTERN, $text);
        $inPerson = (bool) preg_match(self::IN_PERSON_PATTERN, $text);

        if ($video === $inPerson) {
            return null; // neither, or both — the customer hasn't clearly picked
        }

        return $video ? self::VIDEO_CALL : self::IN_PERSON;
    }

    /** Customer-facing label used in WhatsApp confirmations. */
    public static function label(?string $mode): ?string
    {
        return match ($mode) {
            self::VIDEO_CALL => "💻 Online video call — we'll send you the meeting link before your slot.",
            self::IN_PERSON  => '🤝 Face-to-face viewing at the property.',
            default          => null,
        };
    }
}
