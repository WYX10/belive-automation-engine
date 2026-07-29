<?php

declare(strict_types=1);

namespace App\Integrations\Social;

use App\AI\Memory\EpisodicLogger;
use App\Content\PostTimingAdvisor;
use App\Core\Database;
use App\Models\Room;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Approve -> auto-publish orchestrator for content_posts.
 *
 * State machine:
 *   draft --approve--> publish attempt
 *     real success -> status=posted,   publish_status=published
 *     dry-run      -> status=posted,   publish_status=simulated
 *     error        -> status=approved, publish_status=failed (Retry available)
 *   draft --schedule(when)--> status=scheduled, scheduled_for=when
 *   scheduled --due (cron/publish_scheduled.php)--> the same publish attempt
 *   scheduled --unschedule--> status=draft
 *   draft|scheduled --reject--> status=rejected (note required)
 *
 * A scheduled post is its own status rather than an approved row with a future
 * date: cron/publish_retry.php sweeps up every approved row whose publish has
 * not run, and must not touch one that is waiting for its slot.
 *
 * The approval itself commits inside a short transaction with an optimistic
 * review_version check (same pattern as PropertyReviewManager::review); the
 * publish HTTP call runs AFTER commit so a 30s Graph call never holds a row
 * lock, and its outcome lands in a second short update.
 */
final class SocialPublishManager
{
    /** @return array{pending:int, scheduled:int, failed:int, posted:int, rejected:int, all:int} */
    public static function counts(): array
    {
        $counts = ['pending' => 0, 'scheduled' => 0, 'failed' => 0, 'posted' => 0, 'rejected' => 0, 'all' => 0];
        foreach (Database::run(
            "SELECT status, COALESCE(publish_status, '') AS publish_status, COUNT(*) AS total
             FROM content_posts GROUP BY status, publish_status"
        )->fetchAll() as $row) {
            $total = (int) $row['total'];
            $counts['all'] += $total;
            match (true) {
                $row['status'] === 'draft'     => $counts['pending'] += $total,
                $row['status'] === 'scheduled' => $counts['scheduled'] += $total,
                $row['status'] === 'approved'  => $counts['failed'] += $total,
                $row['status'] === 'posted'    => $counts['posted'] += $total,
                $row['status'] === 'rejected'  => $counts['rejected'] += $total,
                default => null,
            };
        }

        return $counts;
    }

    /**
     * Publish now. Also the door a scheduled post goes through when its slot
     * arrives (cron/publish_scheduled.php) or when an admin decides not to wait.
     *
     * @return array<string, mixed> the post row after approval + publish attempt
     */
    public static function approveAndPublish(int $postId, string $reviewer, int $expectedVersion): array
    {
        $post = self::transition($postId, $reviewer, $expectedVersion, 'approved', null, ['draft', 'scheduled']);
        EpisodicLogger::activity('content_approved', 'content_creation', $post['generated_by_model'], null, "post #$postId on {$post['platform']} by $reviewer");

        return self::attemptPublish($post);
    }

    /**
     * Approve a draft for a time instead of for right now. The time is normally
     * one of PostTimingAdvisor's suggested slots; whatever it is, the admin
     * picked it, and cron/publish_scheduled.php is what acts on it.
     *
     * @param string $when   'Y-m-d H:i:s' in the app timezone
     * @param string $source one of CONTENT_SCHEDULE_SOURCES
     * @return array<string, mixed>
     */
    public static function schedule(int $postId, string $reviewer, int $expectedVersion, string $when, string $source = 'custom'): array
    {
        if (!in_array($source, CONTENT_SCHEDULE_SOURCES, true)) {
            throw new InvalidArgumentException('Unknown schedule source.');
        }

        $at = self::parseScheduleTime($when);
        $post = self::transition(
            $postId,
            $reviewer,
            $expectedVersion,
            'scheduled',
            null,
            ['draft', 'scheduled'],
            requireUnpublished: true,
            set: [
                'scheduled_for'   => $at->format('Y-m-d H:i:s'),
                'scheduled_by'    => trim($reviewer),
                'schedule_source' => $source,
            ]
        );

        EpisodicLogger::activity(
            'content_scheduled',
            'content_creation',
            $post['generated_by_model'],
            null,
            "post #$postId on {$post['platform']} scheduled for {$at->format('D j M Y H:i')} by $reviewer ($source)"
        );

        return $post;
    }

    /**
     * Take a post back off the schedule — it returns to the drafts queue
     * untouched, nothing having gone out.
     *
     * @return array<string, mixed>
     */
    public static function unschedule(int $postId, string $reviewer, int $expectedVersion): array
    {
        $post = self::transition(
            $postId,
            $reviewer,
            $expectedVersion,
            'draft',
            null,
            ['scheduled'],
            set: ['scheduled_for' => null, 'scheduled_by' => null, 'schedule_source' => null]
        );

        EpisodicLogger::activity('content_unscheduled', 'content_creation', $post['generated_by_model'], null, "post #$postId on {$post['platform']} by $reviewer");

        return $post;
    }

    /**
     * Posts whose slot has arrived, oldest first. The publisher cron's queue.
     *
     * @return list<array<string, mixed>>
     */
    public static function due(int $max = 10): array
    {
        return Database::run(
            "SELECT id, platform, review_version, scheduled_for FROM content_posts
             WHERE status = 'scheduled' AND scheduled_for IS NOT NULL AND scheduled_for <= NOW()
             ORDER BY scheduled_for ASC
             LIMIT " . max(1, min(100, $max))
        )->fetchAll();
    }

    /**
     * A schedule is only worth setting if the publisher can still get there:
     * far enough ahead for the cron to see it, and inside the window where the
     * room's price and availability are still what the caption claims.
     */
    private static function parseScheduleTime(string $when): \DateTimeImmutable
    {
        $when = trim($when);
        // <input type="datetime-local"> posts 'Y-m-dTH:i'; normalise it first.
        $when = str_replace('T', ' ', $when);
        $zone = new \DateTimeZone(PostTimingAdvisor::TIMEZONE);

        try {
            $at = new \DateTimeImmutable($when, $zone);
        } catch (\Throwable) {
            throw new InvalidArgumentException('That is not a valid date and time.');
        }

        $now = PostTimingAdvisor::now();
        if ($at < $now->modify('+' . CONTENT_SCHEDULE_MIN_LEAD_MINUTES . ' minutes')) {
            throw new InvalidArgumentException(
                'Pick a time at least ' . CONTENT_SCHEDULE_MIN_LEAD_MINUTES
                . ' minutes from now — anything sooner should just be published now.'
            );
        }
        if ($at > $now->modify('+' . CONTENT_SCHEDULE_MAX_DAYS . ' days')) {
            throw new InvalidArgumentException(
                'Schedule within the next ' . CONTENT_SCHEDULE_MAX_DAYS
                . ' days — a room\'s price and availability move, and the caption would go stale.'
            );
        }

        return $at->setTime((int) $at->format('G'), (int) $at->format('i'));
    }

    /** @return array<string, mixed> */
    public static function reject(int $postId, string $reviewer, string $note, int $expectedVersion): array
    {
        if (trim($note) === '') {
            throw new InvalidArgumentException('Add a rejection reason so the next draft can improve.');
        }

        $post = self::transition($postId, $reviewer, $expectedVersion, 'rejected', trim($note), ['draft', 'scheduled']);
        EpisodicLogger::activity('content_rejected', 'content_creation', $post['generated_by_model'], null, "post #$postId on {$post['platform']}: " . mb_substr(trim($note), 0, 200));

        return $post;
    }

    /**
     * Rewrite a caption before it goes out. The AI draft is a starting point,
     * not a verdict — the admin owns the words that get published.
     *
     * Editing takes the same version bump as a review decision: whoever
     * approves next must have seen the caption they are approving.
     *
     * @return array<string, mixed> the post row after the edit
     */
    public static function updateCaption(int $postId, string $caption, string $editor, int $expectedVersion): array
    {
        $caption = trim($caption);
        $editor = trim($editor);
        if ($caption === '') {
            throw new InvalidArgumentException('The caption cannot be empty.');
        }
        if (mb_strlen($caption) > CONTENT_CAPTION_MAX) {
            throw new InvalidArgumentException('The caption must be ' . CONTENT_CAPTION_MAX . ' characters or fewer.');
        }
        if ($editor === '') {
            throw new InvalidArgumentException('The admin editor is required.');
        }
        if ($expectedVersion < 1) {
            throw new InvalidArgumentException('The review version is missing. Reload the content studio.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $post = Database::run('SELECT * FROM content_posts WHERE id = ? FOR UPDATE', [$postId])->fetch();
            if (!$post) {
                throw new RuntimeException('Post not found.');
            }
            if ((int) $post['review_version'] !== $expectedVersion) {
                throw new RuntimeException('This post changed after you opened the page. Reload before editing.');
            }
            // Once it is live on the platform, the platform holds the copy —
            // rewriting our row would only make the two disagree.
            if (in_array($post['publish_status'], ['published', 'simulated'], true) || $post['status'] === 'posted') {
                throw new RuntimeException('This post has already gone out — its caption can no longer be edited.');
            }
            if ($post['status'] === 'rejected') {
                throw new RuntimeException('A rejected draft cannot be edited.');
            }

            Database::run(
                'UPDATE content_posts SET caption = ?, review_version = ? WHERE id = ?',
                [$caption, $expectedVersion + 1, $postId]
            );
            $fresh = Database::run('SELECT * FROM content_posts WHERE id = ?', [$postId])->fetch();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        EpisodicLogger::activity('content_caption_edited', 'content_creation', $fresh['generated_by_model'], null, "post #$postId on {$fresh['platform']} edited by $editor");

        return $fresh;
    }

    /**
     * Publish an approved post whose publish failed — or never ran (legacy
     * rows approved under the old copy-paste flow have publish_status NULL).
     *
     * @return array<string, mixed>
     */
    public static function retryPublish(int $postId, string $reviewer, int $expectedVersion): array
    {
        $post = self::transition($postId, $reviewer, $expectedVersion, 'approved', null, ['approved'], requireUnpublished: true);

        return self::attemptPublish($post);
    }

    /**
     * Locked, version-checked status transition (the PropertyReviewManager
     * pattern). Returns the fresh row after commit.
     *
     * @param string[] $fromStatuses
     * @param array<string, ?string> $set extra columns to write (schedule fields only)
     */
    private static function transition(
        int $postId,
        string $reviewer,
        int $expectedVersion,
        string $toStatus,
        ?string $note,
        array $fromStatuses,
        bool $requireUnpublished = false,
        array $set = []
    ): array {
        $reviewer = trim($reviewer);
        if ($reviewer === '') {
            throw new InvalidArgumentException('The admin reviewer is required.');
        }
        if ($note !== null && mb_strlen($note) > 500) {
            throw new InvalidArgumentException('The review note must be 500 characters or fewer.');
        }
        if ($expectedVersion < 1) {
            throw new InvalidArgumentException('The review version is missing. Reload the content studio.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $post = Database::run('SELECT * FROM content_posts WHERE id = ? FOR UPDATE', [$postId])->fetch();
            if (!$post) {
                throw new RuntimeException('Post not found.');
            }
            if ((int) $post['review_version'] !== $expectedVersion) {
                throw new RuntimeException('This post was reviewed by someone else after you opened the page. Reload before deciding.');
            }
            if (!in_array($post['status'], $fromStatuses, true)) {
                throw new RuntimeException("Only a {$fromStatuses[0]} post can be {$toStatus} — this one is {$post['status']}.");
            }
            if ($requireUnpublished && !in_array($post['publish_status'], ['failed', null], true)) {
                throw new RuntimeException('This post already published — nothing to retry.');
            }

            // Only the schedule columns may ride along — the caller passes
            // column names, so the whitelist is what keeps that safe.
            $extra = '';
            $extraValues = [];
            foreach ($set as $column => $value) {
                if (!in_array($column, ['scheduled_for', 'scheduled_by', 'schedule_source'], true)) {
                    throw new InvalidArgumentException("Column '$column' cannot be set by a transition.");
                }
                $extra .= ", $column = ?";
                $extraValues[] = $value;
            }

            Database::run(
                'UPDATE content_posts
                 SET status = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW(), review_version = ?' . $extra . '
                 WHERE id = ?',
                array_merge([$toStatus, $note, $reviewer, $expectedVersion + 1], $extraValues, [$postId])
            );
            $fresh = Database::run('SELECT * FROM content_posts WHERE id = ?', [$postId])->fetch();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $fresh;
    }

    /**
     * Publish an approved post and record the outcome. Never throws — a
     * platform error becomes publish_status=failed on the row.
     *
     * @return array<string, mixed> the updated row
     */
    private static function attemptPublish(array $post): array
    {
        $postId = (int) $post['id'];
        $mediaKind = $post['media_kind'] ?? 'image';
        // Instagram polls its media container until Meta finishes fetching the
        // image, so a publish can legitimately outlive the default 30s limit —
        // and a reel is transcoded, not just fetched, so it needs longer again.
        set_time_limit($mediaKind === 'video' ? 300 : 120);

        try {
            $mediaUrl = self::resolveMediaUrl($post);
            // A reel whose render never landed must fail as a reel. Falling
            // back to its poster frame would publish a photo the admin never
            // approved.
            if ($mediaKind === 'video' && $mediaUrl === null) {
                throw new RuntimeException('This post is a promo video but has no rendered video attached — regenerate it in the content studio.');
            }

            $result = self::publisherFor($post['platform'])
                ->publish($post['caption'], $mediaUrl, $mediaKind);

            $publishStatus = $result['dry_run'] ? 'simulated' : 'published';
            Database::run(
                "UPDATE content_posts
                 SET status = 'posted', publish_status = ?, publish_error = NULL, external_post_id = ?, posted_at = NOW()
                 WHERE id = ?",
                [$publishStatus, $result['external_id'], $postId]
            );
            EpisodicLogger::activity(
                $result['dry_run'] ? 'content_publish_simulated' : 'content_published',
                'content_creation',
                $post['generated_by_model'],
                null,
                "post #$postId on {$post['platform']} → {$result['external_id']}"
            );
        } catch (Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 500);
            Database::run(
                'UPDATE content_posts SET publish_status = ?, publish_error = ? WHERE id = ?',
                ['failed', $error, $postId]
            );
            EpisodicLogger::activity('content_publish_failed', 'content_creation', $post['generated_by_model'], null, "post #$postId on {$post['platform']}: $error");
        }

        return Database::run('SELECT * FROM content_posts WHERE id = ?', [$postId])->fetch();
    }

    public static function publisherFor(string $platform): SocialPublisherInterface
    {
        return match ($platform) {
            'facebook'  => new FacebookPublisher(),
            'instagram' => new InstagramPublisher(),
            'tiktok'    => new TikTokPublisher(),
            default     => throw new InvalidArgumentException("Unknown platform '$platform'."),
        };
    }

    /**
     * What actually gets published: the rendered video for a video post, the
     * photo for everything else.
     */
    public static function resolveMediaUrl(array $post): ?string
    {
        return ($post['media_kind'] ?? 'image') === 'video'
            ? self::absolute($post['video_url'] ?? null)
            : self::resolveImageUrl($post);
    }

    /**
     * The post's stored image, else the room's first photo. For a video post
     * this is the poster frame the studio previews, not what gets published.
     */
    public static function resolveImageUrl(array $post): ?string
    {
        $url = $post['image_url'] ?? null;
        if (($url === null || $url === '') && !empty($post['room_id'])) {
            $url = Room::photoUrls((int) $post['room_id'])[0] ?? null;
        }

        return self::absolute($url);
    }

    /**
     * Site-local paths (/assets/img/rooms/...) become absolute — platforms
     * fetch media by URL, so APP_URL must be the public base (same rule as
     * WhatsApp image sends).
     */
    private static function absolute(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        return str_starts_with($url, '/')
            ? rtrim($_ENV['APP_URL'] ?? 'http://localhost:8080', '/') . $url
            : $url;
    }
}
