<?php

declare(strict_types=1);

namespace App\Pipeline\Conversion;

/**
 * Cheap pre-router in front of the full skill pipeline: coarse keyword
 * classification that lets ConversationManager short-circuit pure smalltalk
 * ("thanks!", "ok") with an instant canned ack instead of burning four model
 * calls. Anything substantive falls through to UnderstandSkill — this class
 * never overrides the real NLP, it only filters the trivial.
 */
final class IntentClassifier
{
    public const SMALLTALK = 'smalltalk';
    public const STOP = 'stop';
    public const SUBSTANTIVE = 'substantive';

    public static function preClassify(string $text): string
    {
        $trimmed = mb_strtolower(trim($text));

        if (preg_match('/^(stop|unsubscribe|berhenti)\b/u', $trimmed)) {
            return self::STOP;
        }

        // Short pleasantries with no rental-related content at all.
        $isShort = mb_strlen($trimmed) <= 25;
        $pleasantry = (bool) preg_match(
            '/^(ok(ay)?|thanks?( you)?|thank u|tq|terima kasih|noted|great|nice|👍|🙏|haha+|lol)[\s!.😊🙂👍🙏]*$/iu',
            $trimmed
        );

        return ($isShort && $pleasantry) ? self::SMALLTALK : self::SUBSTANTIVE;
    }

    public static function smalltalkReply(?string $name): string
    {
        $greeting = $name !== null && $name !== '' ? " $name" : '';

        return "Anytime$greeting! 😊 If you'd like to see more rooms or book a viewing, just message me — Find It. Book It. Live In It.";
    }
}
