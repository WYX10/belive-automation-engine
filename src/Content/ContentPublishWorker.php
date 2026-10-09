<?php

declare(strict_types=1);

namespace App\Content;

use App\Core\Database;
use App\Core\Settings;
use App\Integrations\Social\SocialPublishManager;

/** Shared delivery cycle for CLI workers, cron and the authenticated HTTP tick. */
final class ContentPublishWorker
{
    /** The optional publisher resolver lets integration tests exercise delivery without public posts. */
    public static function runDue(int $max = 10, ?callable $publisherResolver = null): array
    {
        $result = ['attempted' => 0, 'published' => 0, 'simulated' => 0, 'failed' => 0,
            'blocked' => 0, 'uncertain' => 0, 'skipped' => 0];
        $db = (string) Database::run('SELECT DATABASE()')->fetchColumn();
        $lock = 'content_delivery_' . substr(hash('sha256', $db), 0, 32);
        if (!Database::acquireLock($lock)) {
            return $result + ['busy' => true];
        }
        try {
            Settings::refresh();
            Settings::set('content_publisher_heartbeat', gmdate('Y-m-d H:i:s'), 'scheduler');
            // A process lost during an HTTP call may already have posted.
            // Require confirmation instead of guessing and creating duplicates.
            $result['uncertain'] = Database::run(
                "UPDATE content_posts SET publish_status = 'uncertain', next_retry_at = NULL,
                    publish_error = 'Delivery stopped before confirmation. Check the social account before retrying.'
                 WHERE status = 'approved' AND publish_status = 'publishing'
                    AND publish_attempted_at < UTC_TIMESTAMP() - INTERVAL 15 MINUTE"
            )->rowCount();
            $max = max(1, min(100, $max));
            $queue = array_map(static fn (array $p): array => $p + ['retry' => false], SocialPublishManager::due($max));
            $retries = Database::run(
                "SELECT id, platform, review_version FROM content_posts
                 WHERE status = 'approved' AND publish_attempts < 8
                    AND (publish_status = 'failed' OR publish_status IS NULL)
                    AND ((next_retry_at IS NOT NULL AND next_retry_at <= UTC_TIMESTAMP())
                        OR (next_retry_at IS NULL AND publish_attempted_at IS NOT NULL AND publish_attempted_at < UTC_TIMESTAMP() - INTERVAL 10 MINUTE)
                        OR (next_retry_at IS NULL AND publish_attempted_at IS NULL AND COALESCE(reviewed_at, created_at) < NOW() - INTERVAL 10 MINUTE))
                 ORDER BY COALESCE(next_retry_at, publish_attempted_at, reviewed_at, created_at) LIMIT $max"
            )->fetchAll();
            foreach ($retries as $post) {
                $queue[] = $post + ['retry' => true];
            }
            foreach ($queue as $row) {
                try {
                    Settings::set('content_publisher_heartbeat', gmdate('Y-m-d H:i:s'), 'scheduler');
                    $publisher = $publisherResolver !== null ? $publisherResolver($row['platform']) : SocialPublishManager::publisherFor($row['platform']);
                    if (!$publisher->isConfigured() && $row['retry']) {
                        $result['blocked']++;
                        continue;
                    }
                    $post = $row['retry']
                        ? SocialPublishManager::retryPublish((int) $row['id'], 'scheduler', (int) $row['review_version'], $publisher)
                        : SocialPublishManager::publishScheduled((int) $row['id'], (int) $row['review_version'], $publisher);
                    if ($post['status'] === 'scheduled') {
                        $result['blocked']++;
                        continue;
                    }
                    $result['attempted']++;
                    $state = $post['publish_status'];
                    if ($state === 'published') {
                        $result['published']++;
                    } elseif ($state === 'simulated') {
                        $result['simulated']++;
                    } elseif ($state === 'uncertain') {
                        $result['uncertain']++;
                    } elseif ($state === 'failed') {
                        $result['failed']++;
                    } else {
                        $result['skipped']++;
                    }
                } catch (\Throwable $e) {
                    $result['skipped']++;
                    error_log('[content delivery] post #' . $row['id'] . ': ' . $e->getMessage());
                }
            }
            Settings::set('content_publisher_heartbeat', gmdate('Y-m-d H:i:s'), 'scheduler');
            Settings::set('content_publisher_last_result', json_encode($result), 'scheduler');
            return $result + ['busy' => false];
        } finally {
            Database::releaseLock($lock);
        }
    }

    public static function status(): array
    {
        $last = Settings::get('content_publisher_heartbeat');
        $at = $last !== '' ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $last, new \DateTimeZone('UTC')) : false;
        return ['active' => $at !== false && $at->getTimestamp() >= time() - 120,
            'last_seen' => $at !== false ? $at->setTimezone(new \DateTimeZone(PostTimingAdvisor::TIMEZONE)) : null];
    }
}
