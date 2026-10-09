<?php

declare(strict_types=1);

namespace App\Content;

use App\Core\Settings;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class PostingSchedule
{
    public static function validateTime(string $time): string
    {
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time)) {
            throw new InvalidArgumentException('Choose a posting time between 00:00 and 23:59.');
        }
        return $time;
    }

    public static function time(): string
    {
        $time = Settings::get('content_publish_time', '18:00');
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time) ? $time : '18:00';
    }

    public static function next(?DateTimeImmutable $now = null): DateTimeImmutable
    {
        $now = ($now ?? PostTimingAdvisor::now())->setTimezone(new DateTimeZone(PostTimingAdvisor::TIMEZONE));
        [$hour, $minute] = array_map('intval', explode(':', self::time()));
        $slot = $now->setTime($hour, $minute, 0);
        if ($slot < $now->modify('+' . CONTENT_SCHEDULE_MIN_LEAD_MINUTES . ' minutes')) {
            $slot = $slot->modify('+1 day');
        }
        return $slot;
    }
}
