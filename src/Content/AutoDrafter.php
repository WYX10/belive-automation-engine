<?php

declare(strict_types=1);

namespace App\Content;

use App\AI\Memory\EpisodicLogger;
use App\Core\Database;
use App\Core\Settings;
use App\Integrations\Social\SocialPublishManager;
use DateTimeImmutable;

/**
 * The daily draft run itself — one place that decides whether today's run is
 * owed, claims it so it happens exactly once, does the drafting, and records
 * what happened for the studio to display.
 *
 * It used to live inside cron/auto_draft_content.php, which meant the "runs
 * once a day" promise depended entirely on something outside the app calling
 * that file — and Azure App Service has no crontab, so nothing ever did. Now
 * the schedule lives in the database: any request can ask "is the run owed?"
 * (Scheduler::tick) and only the first caller past the daily hour gets to run
 * it. The cron file, the web tick and the studio's Run now button are three
 * doors into this one method.
 *
 * Daily drafts can be queued for the saved posting time when the admin enables
 * automatic scheduling. Otherwise they wait for individual review.
 */
final class AutoDrafter
{
    /** Local hour the daily run is owed from, when the admin hasn't picked one. */
    public const DEFAULT_HOUR = 9;

    /** A run that started this long ago and never finished is treated as dead. */
    private const STALE_RUN_MINUTES = 30;

    private const K_ENABLED   = 'content_auto_enabled';
    private const K_HOUR      = 'content_auto_hour';
    /** The daily slot the last claim covered — the once-a-day guard. */
    private const K_CLAIMED   = 'content_auto_claimed_slot';
    private const K_STARTED   = 'content_auto_last_started';
    private const K_FINISHED  = 'content_auto_last_finished';
    private const K_RESULT    = 'content_auto_last_result';
    private const K_DRAFTED   = 'content_auto_last_drafted';
    private const K_TRIGGER   = 'content_auto_last_trigger';

    public static function isEnabled(): bool
    {
        return Settings::get(self::K_ENABLED, '1') === '1';
    }

    /** The Pause / Resume button. Paused means no scheduled run — manual still works. */
    public static function setEnabled(bool $on, ?string $by = null): void
    {
        Settings::set(self::K_ENABLED, $on ? '1' : '0', $by);
        EpisodicLogger::activity(
            $on ? 'content_auto_resumed' : 'content_auto_paused',
            'content_creation',
            null,
            null,
            'Daily auto-drafting ' . ($on ? 'resumed' : 'paused') . ' by ' . ($by ?? 'admin')
        );
    }

    /** Local hour (0–23) the day's run is owed from. */
    public static function hour(): int
    {
        $hour = Settings::getInt(self::K_HOUR, self::DEFAULT_HOUR);

        return $hour >= 0 && $hour <= 23 ? $hour : self::DEFAULT_HOUR;
    }

    public static function setHour(int $hour, ?string $by = null): void
    {
        Settings::set(self::K_HOUR, (string) max(0, min(23, $hour)), $by);
    }

    /** The most recent daily slot that has already arrived (today's, or yesterday's). */
    public static function dueSlot(?DateTimeImmutable $now = null): DateTimeImmutable
    {
        $now ??= new DateTimeImmutable();
        $slot = $now->setTime(self::hour(), 0);

        return $slot <= $now ? $slot : $slot->modify('-1 day');
    }

    /** The next slot nobody has drafted for yet — what the card counts down to. */
    public static function nextSlot(?DateTimeImmutable $now = null): DateTimeImmutable
    {
        $due = self::dueSlot($now);

        return self::claimedSlot() < $due->format('Y-m-d H:i:s') ? $due : $due->modify('+1 day');
    }

    /** True when the run for the current slot is owed and nothing has claimed it. */
    public static function isDue(?DateTimeImmutable $now = null): bool
    {
        return self::isEnabled()
            && self::claimedSlot() < self::dueSlot($now)->format('Y-m-d H:i:s');
    }

    /**
     * Everything the studio card shows, in one read.
     *
     * @return array{enabled:bool, hour:int, due:bool, running:bool, next_slot:DateTimeImmutable,
     *               last_started:?DateTimeImmutable, last_finished:?DateTimeImmutable,
     *               last_result:string, last_drafted:int, last_trigger:string}
     */
    public static function status(?DateTimeImmutable $now = null): array
    {
        $started = self::timestamp(self::K_STARTED);
        $finished = self::timestamp(self::K_FINISHED);

        return [
            'enabled'       => self::isEnabled(),
            'hour'          => self::hour(),
            'due'           => self::isDue($now),
            'running'       => self::isRunning($now),
            'next_slot'     => self::nextSlot($now),
            'last_started'  => $started,
            'last_finished' => $finished,
            'last_result'   => Settings::get(self::K_RESULT, ''),
            'last_drafted'  => Settings::getInt(self::K_DRAFTED, 0),
            'last_trigger'  => Settings::get(self::K_TRIGGER, ''),
        ];
    }

    /**
     * Run the day's drafting if it is owed. Returns null when it is not (paused,
     * too early, or another caller already claimed this slot) — so this is safe
     * to call on every page load and from a scheduler at any frequency.
     *
     * @param array{platforms?:string[], max?:int, media?:string, brief?:string} $overrides
     * @return array{drafted:int, lines:string[], slot:string, aborted:?string}|null
     */
    public static function runIfDue(string $trigger = 'cron', array $overrides = [], ?DateTimeImmutable $now = null): ?array
    {
        if (!self::isEnabled()) {
            return null;
        }

        $slot = self::dueSlot($now);
        if (!self::claimSlot($slot, $trigger)) {
            return null;
        }

        return self::draft($slot, $trigger, $overrides);
    }

    /**
     * Draft now whatever the schedule says — the studio's Run now button and a
     * bare `php cron/auto_draft_content.php`. Claims the current slot too, so a
     * manual run that happens after today's hour also settles today's run
     * instead of leaving a second one owed.
     *
     * @param array{platforms?:string[], max?:int, media?:string, brief?:string} $overrides
     * @return array{drafted:int, lines:string[], slot:string, aborted:?string}
     */
    public static function runNow(string $trigger = 'manual', array $overrides = [], ?DateTimeImmutable $now = null): array
    {
        $slot = self::dueSlot($now);

        if (self::isRunning($now)) {
            return ['drafted' => 0, 'lines' => [], 'slot' => $slot->format('Y-m-d H:i'),
                    'aborted' => 'a run is already in progress — give it a moment'];
        }

        self::claimSlot($slot, $trigger);

        return self::draft($slot, $trigger, $overrides);
    }

    /** True while a run is in flight (used to keep two runs from overlapping). */
    public static function isRunning(?DateTimeImmutable $now = null): bool
    {
        $started = self::timestamp(self::K_STARTED);
        if ($started === null) {
            return false;
        }

        $finished = self::timestamp(self::K_FINISHED);
        if ($finished !== null && $finished >= $started) {
            return false;
        }

        // A crashed run must not wedge the schedule shut for ever.
        return $started > ($now ?? new DateTimeImmutable())->modify('-' . self::STALE_RUN_MINUTES . ' minutes');
    }

    /**
     * The drafting itself. Rounds over the platforms rather than taking one each,
     * so "drafts per run = 5" across 3 platforms really is 5 drafts.
     *
     * @param array{platforms?:string[], max?:int, media?:string, brief?:string} $overrides
     * @return array{drafted:int, lines:string[], slot:string, aborted:?string}
     */
    private static function draft(DateTimeImmutable $slot, string $trigger, array $overrides): array
    {
        $platforms = $overrides['platforms']
            ?? array_values(array_intersect(Settings::getList('content_auto_platforms', CONTENT_PLATFORMS), CONTENT_PLATFORMS));
        $max = max(1, $overrides['max'] ?? Settings::getInt('content_auto_max', 3));
        $brief = $overrides['brief'] ?? Settings::get('content_brief', '');
        $media = $overrides['media'] ?? Settings::get('content_auto_media', 'image');
        $media = in_array($media, CONTENT_MEDIA_KINDS, true) ? $media : 'image';

        self::mark(self::K_STARTED, $trigger);

        // A run that quietly wrote captions when it was told to make reels would
        // be the worst of both outcomes — stop, and say why, where it shows.
        if ($media === 'video' && !RoomVideoComposer::isAvailable()) {
            return self::finish($slot, $trigger, 0, [], 'ffmpeg was not found — install it or set FFMPEG_BIN in .env before scheduling reels');
        }
        if ($platforms === []) {
            return self::finish($slot, $trigger, 0, [], 'no platforms are selected');
        }

        // Admin → Reports counts "written without anyone asking" off generated_via,
        // so a Run now press is a manual draft even though the batch is the
        // schedule's own — a person asked for it.
        $via = str_starts_with($trigger, 'admin:') ? 'manual' : 'cron';

        $drafted = 0;
        $lines = [];
        $exhausted = [];

        while ($drafted < $max && count($exhausted) < count($platforms)) {
            $progressed = false;

            foreach ($platforms as $platform) {
                if ($drafted >= $max || in_array($platform, $exhausted, true)) {
                    continue;
                }

                try {
                    $room = self::nextRoom($platform);
                    if ($room === null) {
                        $exhausted[] = $platform;
                        $lines[] = "$platform: no eligible room (all have pending posts, or none available)";
                        continue;
                    }

                    if ($media === 'video') {
                        $video = PromoVideoDrafter::draft($room, $platform, $brief, $via);
                        $postId = $video['post_id'];
                        $lines[] = sprintf(
                            '%s: reel for %s — %d scenes, %.1fs, by %s',
                            $platform,
                            $room['name'],
                            $video['scenes'],
                            $video['seconds'],
                            $video['model']
                        );
                    } else {
                        $photo = PhotoPostDrafter::draft($room, $platform, $brief, $via);
                        $postId = $photo['post_id'];
                        $lines[] = "$platform: caption for {$room['name']} by {$photo['model']}"
                            . ($photo['branded'] ? ' (mascot branded)' : '');
                    }

                    if (Settings::get('content_auto_publish_enabled', '0') === '1') {
                        $post = Database::run('SELECT review_version FROM content_posts WHERE id = ?', [$postId])->fetch();
                        $at = PostingSchedule::next();
                        SocialPublishManager::schedule((int) $postId, 'daily-automation', (int) $post['review_version'], $at->format('Y-m-d H:i:s'), 'auto');
                        $lines[] = "$platform: post #$postId queued for " . $at->format('D j M H:i') . ' (Asia/Kuala_Lumpur)';
                    }

                    $drafted++;
                    $progressed = true;
                } catch (\Throwable $e) {
                    // One platform's failure must not cost the others their draft.
                    $exhausted[] = $platform;
                    $lines[] = "$platform: FAILED — {$e->getMessage()}";
                }
            }

            if (!$progressed) {
                break;
            }
        }

        return self::finish($slot, $trigger, $drafted, $lines, null);
    }

    /**
     * Least-recently-featured available room with nothing pending on this
     * platform — the LEFT JOIN ... IS NULL is the duplicate guard. A room
     * already waiting for its scheduled slot counts as pending too.
     *
     * @return array<string, mixed>|null
     */
    private static function nextRoom(string $platform): ?array
    {
        $room = Database::run(
            "SELECT r.* FROM rooms r
             LEFT JOIN content_posts p ON p.room_id = r.id AND p.platform = ?
                  AND p.status IN ('draft', 'scheduled', 'approved')
             WHERE r.status = 'available' AND p.id IS NULL
             ORDER BY (SELECT MAX(created_at) FROM content_posts WHERE room_id = r.id) IS NOT NULL,
                      (SELECT MAX(created_at) FROM content_posts WHERE room_id = r.id) ASC,
                      r.id ASC
             LIMIT 1",
            [$platform]
        )->fetch();

        return $room === false ? null : $room;
    }

    /**
     * Take the day's run, atomically. The UPDATE only lands when the stored slot
     * is older than this one, so of two page loads (or a page load racing the
     * scheduler) exactly one is told to draft — no double posting.
     */
    private static function claimSlot(DateTimeImmutable $slot, string $trigger): bool
    {
        if (self::isRunning()) {
            return false;
        }

        $stamp = $slot->format('Y-m-d H:i:s');
        Database::run('INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES (?, ?)', [self::K_CLAIMED, '']);
        $claimed = Database::run(
            'UPDATE app_settings SET setting_value = ?, updated_by = ?
             WHERE setting_key = ? AND (setting_value IS NULL OR setting_value < ?)',
            [$stamp, self::by($trigger), self::K_CLAIMED, $stamp]
        )->rowCount() > 0;

        // Written round the back of Settings::set, so drop its cached copy.
        Settings::refresh();

        return $claimed;
    }

    private static function claimedSlot(): string
    {
        return Settings::get(self::K_CLAIMED, '');
    }

    /** @return array{drafted:int, lines:string[], slot:string, aborted:?string} */
    private static function finish(DateTimeImmutable $slot, string $trigger, int $drafted, array $lines, ?string $aborted): array
    {
        $summary = $aborted !== null
            ? "aborted — $aborted"
            : ($drafted === 0 ? 'nothing to draft' : "$drafted draft(s)")
                . ($lines === [] ? '' : ' — ' . implode(' · ', $lines));

        self::mark(self::K_FINISHED, $trigger);
        Settings::set(self::K_RESULT, mb_substr($summary, 0, 900), self::by($trigger));
        Settings::set(self::K_DRAFTED, (string) $drafted, self::by($trigger));

        // An aborted slot stays claimed on purpose: both abort causes need a
        // human (install ffmpeg, tick a platform), and releasing the slot would
        // make every following page load retry and fail the same way.
        EpisodicLogger::activity(
            'content_auto_run',
            'content_creation',
            null,
            null,
            "Daily auto-draft run ($trigger) for slot {$slot->format('Y-m-d H:i')}: $summary"
        );

        return ['drafted' => $drafted, 'lines' => $lines, 'slot' => $slot->format('Y-m-d H:i'), 'aborted' => $aborted];
    }

    private static function mark(string $key, string $trigger): void
    {
        Settings::set($key, (new DateTimeImmutable())->format('Y-m-d H:i:s'), self::by($trigger));
        Settings::set(self::K_TRIGGER, $trigger, self::by($trigger));
    }

    /** app_settings.updated_by is VARCHAR(60); a long admin name must not fail a run. */
    private static function by(string $trigger): string
    {
        return mb_substr($trigger, 0, 60);
    }

    private static function timestamp(string $key): ?DateTimeImmutable
    {
        $value = Settings::get($key, '');

        try {
            return $value === '' ? null : new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
