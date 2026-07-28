<?php

declare(strict_types=1);

namespace App\Integrations\Social;

use App\AI\Memory\EpisodicLogger;
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
 *   draft --reject--> status=rejected (note required)
 *
 * The approval itself commits inside a short transaction with an optimistic
 * review_version check (same pattern as PropertyReviewManager::review); the
 * publish HTTP call runs AFTER commit so a 30s Graph call never holds a row
 * lock, and its outcome lands in a second short update.
 */
final class SocialPublishManager
{
    /** @return array{pending:int, failed:int, posted:int, rejected:int, all:int} */
    public static function counts(): array
    {
        $counts = ['pending' => 0, 'failed' => 0, 'posted' => 0, 'rejected' => 0, 'all' => 0];
        foreach (Database::run(
            "SELECT status, COALESCE(publish_status, '') AS publish_status, COUNT(*) AS total
             FROM content_posts GROUP BY status, publish_status"
        )->fetchAll() as $row) {
            $total = (int) $row['total'];
            $counts['all'] += $total;
            match (true) {
                $row['status'] === 'draft'    => $counts['pending'] += $total,
                $row['status'] === 'approved' => $counts['failed'] += $total,
                $row['status'] === 'posted'   => $counts['posted'] += $total,
                $row['status'] === 'rejected' => $counts['rejected'] += $total,
                default => null,
            };
        }

        return $counts;
    }

    /** @return array<string, mixed> the post row after approval + publish attempt */
    public static function approveAndPublish(int $postId, string $reviewer, int $expectedVersion): array
    {
        $post = self::transition($postId, $reviewer, $expectedVersion, 'approved', null, ['draft']);
        EpisodicLogger::activity('content_approved', 'content_creation', $post['generated_by_model'], null, "post #$postId on {$post['platform']} by $reviewer");

        return self::attemptPublish($post);
    }

    /** @return array<string, mixed> */
    public static function reject(int $postId, string $reviewer, string $note, int $expectedVersion): array
    {
        if (trim($note) === '') {
            throw new InvalidArgumentException('Add a rejection reason so the next draft can improve.');
        }

        $post = self::transition($postId, $reviewer, $expectedVersion, 'rejected', trim($note), ['draft']);
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
     */
    private static function transition(
        int $postId,
        string $reviewer,
        int $expectedVersion,
        string $toStatus,
        ?string $note,
        array $fromStatuses,
        bool $requireUnpublished = false
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

            Database::run(
                'UPDATE content_posts
                 SET status = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW(), review_version = ?
                 WHERE id = ?',
                [$toStatus, $note, $reviewer, $expectedVersion + 1, $postId]
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
        // Instagram polls its media container until Meta finishes fetching the
        // image, so a publish can legitimately outlive the default 30s limit.
        set_time_limit(120);

        try {
            $result = self::publisherFor($post['platform'])
                ->publish($post['caption'], self::resolveImageUrl($post));

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
     * The post's stored image, else the room's first photo. Site-local paths
     * (/assets/img/rooms/...) become absolute — platforms fetch media by URL,
     * so APP_URL must be the public base (same rule as WhatsApp image sends).
     */
    public static function resolveImageUrl(array $post): ?string
    {
        $url = $post['image_url'] ?? null;
        if (($url === null || $url === '') && !empty($post['room_id'])) {
            $url = Room::photoUrls((int) $post['room_id'])[0] ?? null;
        }
        if ($url === null || $url === '') {
            return null;
        }
        if (str_starts_with($url, '/')) {
            $url = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8080', '/') . $url;
        }

        return $url;
    }
}
