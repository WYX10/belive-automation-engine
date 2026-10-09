<?php

declare(strict_types=1);

use App\Content\ContentPublishWorker;
use App\Content\PostingSchedule;
use App\Content\PostTimingAdvisor;
use App\Core\Database;
use App\Core\Settings;
use App\Integrations\Social\SocialPublishManager;
use App\Integrations\Social\SocialPublisherInterface;

final class StudioTestPublisher implements SocialPublisherInterface
{
    public bool $configured = true;
    public bool $fail = false;
    public bool $missingId = false;
    public int $calls = 0;
    public array $deliveries = [];
    public function isConfigured(): bool { return $this->configured; }
    public function publish(string $caption, ?string $mediaUrl, string $mediaKind = 'image'): array
    {
        $this->calls++;
        if ($this->fail) {
            throw new RuntimeException('Temporary platform rejection');
        }
        $this->deliveries[] = [$caption, $mediaUrl, $mediaKind];
        return ['external_id' => $this->missingId ? '' : 'fixture-post-' . $this->calls, 'dry_run' => false];
    }
}

$studioPublisher = new StudioTestPublisher();
$studioResolver = static fn (string $platform): StudioTestPublisher => $studioPublisher;
$studioIds = [];
$studioMake = static function (string $when, string $kind = 'image') use (&$studioIds): array {
    Database::run("INSERT INTO content_posts(platform,media_kind,caption,status,generated_by_model,image_url,video_url)
        VALUES('facebook',?,'Delivery fixture','draft','delivery-test','/assets/img/rooms/riamas-master-ensuite-1.jpg',?)",
        [$kind, $kind === 'video' ? '/assets/img/uploads/videos/fixture.mp4' : null]);
    $id = (int) Database::pdo()->lastInsertId();
    $studioIds[] = $id;
    $post = Database::run('SELECT * FROM content_posts WHERE id = ?', [$id])->fetch();
    $scheduled = SocialPublishManager::schedule($id, 'delivery-test', (int) $post['review_version'], PostTimingAdvisor::now()->modify('+2 hours')->format('Y-m-d H:i:s'));
    Database::run('UPDATE content_posts SET scheduled_for = ? WHERE id = ?', [$when, $id]);
    return Database::run('SELECT * FROM content_posts WHERE id = ?', [$id])->fetch();
};
$studioTimezone = Database::run(Database::isPostgres() ? 'SHOW TimeZone' : 'SELECT @@session.time_zone')->fetchColumn();
$studioPostingTime = PostingSchedule::time();
try {
    Settings::set('content_publish_time', '18:30', 'delivery-test');
    $slot = PostingSchedule::next(new DateTimeImmutable('2026-10-08 09:00:00', new DateTimeZone('UTC')));
    check('a daily posting time is interpreted in Kuala Lumpur even from a UTC clock', $slot->format('Y-m-d H:i:s P') === '2026-10-08 18:30:00 +08:00');
    $slot = PostingSchedule::next(new DateTimeImmutable('2026-10-08 18:25:00', new DateTimeZone('Asia/Kuala_Lumpur')));
    check('a missed daily time rolls to tomorrow with enough scheduling lead', $slot->format('Y-m-d H:i') === '2026-10-09 18:30');
    foreach (['24:00', '18:60', '6:30', 'tomorrow'] as $badTime) {
        $rejected = false;
        try { PostingSchedule::validateTime($badTime); } catch (InvalidArgumentException) { $rejected = true; }
        check('invalid daily posting time is rejected: ' . $badTime, $rejected);
    }

    Database::run(Database::isPostgres() ? "SET TIME ZONE 'UTC'" : "SET time_zone = '+00:00'");
    $future = $studioMake(PostTimingAdvisor::now()->modify('+1 minute')->format('Y-m-d H:i:s'));
    $due = $studioMake(PostTimingAdvisor::now()->modify('-1 second')->format('Y-m-d H:i:s'), 'video');
    $cycle = ContentPublishWorker::runDue(10, $studioResolver);
    $sent = Database::run('SELECT * FROM content_posts WHERE id = ?', [$due['id']])->fetch();
    $waiting = Database::run('SELECT * FROM content_posts WHERE id = ?', [$future['id']])->fetch();
    check('the worker publishes a Kuala Lumpur slot on time with a UTC database', $sent['status'] === 'posted' && $sent['publish_status'] === 'published' && $cycle['published'] === 1);
    check('the recorded delivery time also uses the studio timezone', abs(strtotime($sent['posted_at']) - time()) < 5);
    check('a future post is never sent early', $waiting['status'] === 'scheduled' && $studioPublisher->calls === 1);
    check('scheduled videos deliver the video URL and video kind', $studioPublisher->deliveries[0][2] === 'video' && str_ends_with($studioPublisher->deliveries[0][1], '/fixture.mp4'));
    ContentPublishWorker::runDue(10, $studioResolver);
    check('a second delivery cycle cannot publish the same post again', $studioPublisher->calls === 1);
    $earlyRejected = false;
    try { SocialPublishManager::publishScheduled((int) $future['id'], (int) $future['review_version'], $studioPublisher); }
    catch (RuntimeException) { $earlyRejected = true; }
    check('the locked publishing path also refuses future slots', $earlyRejected && $studioPublisher->calls === 1);
    Database::run("UPDATE content_posts SET status = 'rejected' WHERE id = ?", [$future['id']]);

    $disconnected = $studioMake(PostTimingAdvisor::now()->modify('-1 second')->format('Y-m-d H:i:s'));
    $studioPublisher->configured = false;
    $cycle = ContentPublishWorker::runDue(10, $studioResolver);
    $held = Database::run('SELECT * FROM content_posts WHERE id = ?', [$disconnected['id']])->fetch();
    check('missing social credentials keep a due post queued instead of consuming it as simulated', $held['status'] === 'scheduled' && $held['publish_status'] === null && $held['external_post_id'] === null && $cycle['blocked'] === 1);
    check('a disconnected account does not start delivery attempts', (int) $held['publish_attempts'] === 0 && $studioPublisher->calls === 1);
    $studioPublisher->configured = true;
    Database::run('UPDATE content_posts SET next_retry_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = ?', [$held['id']]);
    ContentPublishWorker::runDue(10, $studioResolver);
    check('a queued post resumes once its social account is connected', Database::run('SELECT status FROM content_posts WHERE id = ?', [$held['id']])->fetchColumn() === 'posted');

    Database::run(Database::isPostgres() ? "SET TIME ZONE 'Asia/Kuala_Lumpur'" : "SET time_zone = '+08:00'");
    $failure = $studioMake(PostTimingAdvisor::now()->modify('-1 second')->format('Y-m-d H:i:s'));
    $studioPublisher->fail = true;
    ContentPublishWorker::runDue(10, $studioResolver);
    $failedPost = Database::run('SELECT * FROM content_posts WHERE id = ?', [$failure['id']])->fetch();
    check('a failed delivery stores an attempt and a UTC retry deadline', $failedPost['status'] === 'approved' && $failedPost['publish_status'] === 'failed' && (int) $failedPost['publish_attempts'] === 1 && strtotime($failedPost['next_retry_at'] . ' UTC') >= time() + 590);
    $beforeCalls = $studioPublisher->calls;
    ContentPublishWorker::runDue(10, $studioResolver);
    check('automatic retries respect the cooldown even on a UTC+8 database', $studioPublisher->calls === $beforeCalls);
    $studioPublisher->fail = false;
    Database::run('UPDATE content_posts SET next_retry_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = ?', [$failure['id']]);
    ContentPublishWorker::runDue(10, $studioResolver);
    $retried = Database::run('SELECT * FROM content_posts WHERE id = ?', [$failure['id']])->fetch();
    check('a failed post retries after the deadline and records its platform ID', $retried['status'] === 'posted' && (int) $retried['publish_attempts'] === 2 && $retried['external_post_id'] !== '');

    $lost = $studioMake(PostTimingAdvisor::now()->modify('-1 second')->format('Y-m-d H:i:s'));
    Database::run("UPDATE content_posts SET status = 'approved', publish_status = 'publishing', publish_attempted_at = UTC_TIMESTAMP() - INTERVAL 16 MINUTE WHERE id = ?", [$lost['id']]);
    $beforeCalls = $studioPublisher->calls;
    ContentPublishWorker::runDue(10, $studioResolver);
    $unknown = Database::run('SELECT * FROM content_posts WHERE id = ?', [$lost['id']])->fetch();
    check('a lost delivery requires confirmation instead of risking a duplicate', $unknown['publish_status'] === 'uncertain' && $studioPublisher->calls === $beforeCalls);
    $unconfirmedRejected = false;
    try { SocialPublishManager::retryPublish((int) $lost['id'], 'delivery-test', (int) $unknown['review_version'], $studioPublisher); }
    catch (RuntimeException) { $unconfirmedRejected = true; }
    check('an unconfirmed delivery cannot be retried without checking the social account', $unconfirmedRejected);
    $confirmed = SocialPublishManager::retryPublish((int) $lost['id'], 'delivery-test', (int) $unknown['review_version'], $studioPublisher, true);
    check('a checked absent post can be explicitly retried', $confirmed['status'] === 'posted');

    $noId = $studioMake(PostTimingAdvisor::now()->modify('-1 second')->format('Y-m-d H:i:s'));
    $studioPublisher->missingId = true;
    ContentPublishWorker::runDue(10, $studioResolver);
    $noConfirmation = Database::run('SELECT * FROM content_posts WHERE id = ?', [$noId['id']])->fetch();
    check('a platform response without a post ID is never reported as successful delivery', $noConfirmation['status'] === 'approved' && $noConfirmation['publish_status'] === 'uncertain');
    $studioPublisher->missingId = false;
    $beforeCalls = $studioPublisher->calls;
    ContentPublishWorker::runDue(10, $studioResolver);
    check('a platform response without confirmation cannot trigger duplicate automatic retries', $studioPublisher->calls === $beforeCalls);

    $capped = $studioMake(PostTimingAdvisor::now()->modify('-1 second')->format('Y-m-d H:i:s'));
    Database::run("UPDATE content_posts SET status = 'approved', publish_status = 'failed', publish_attempts = 8, next_retry_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = ?", [$capped['id']]);
    $beforeCalls = $studioPublisher->calls;
    ContentPublishWorker::runDue(10, $studioResolver);
    check('automatic retries stop after eight attempts', $studioPublisher->calls === $beforeCalls);

    $dbName = (string) Database::run('SELECT DATABASE()')->fetchColumn();
    $lockName = 'content_delivery_' . substr(hash('sha256', $dbName), 0, 32);
    $lockCfg = $cfg;
    $lockCfg['name'] = $dbName;
    $lockPdo = Database::connect($lockCfg);
    Database::acquireLock($lockName, 0, $lockPdo);
    $busy = ContentPublishWorker::runDue(10, $studioResolver);
    Database::releaseLock($lockName, $lockPdo);
    check('overlapping worker processes cannot run the delivery cycle together', $busy['busy'] === true && $studioPublisher->calls === $beforeCalls);
    check('the studio can report a running publisher heartbeat', ContentPublishWorker::status()['active']);

    foreach (['tomorrow 18:00', '', '2026-10-32 18:00', '2026-10-20 18:00 +00:00'] as $invalid) {
        $invalidRejected = false;
        try { SocialPublishManager::schedule((int) $future['id'], 'delivery-test', 1, $invalid); }
        catch (InvalidArgumentException) { $invalidRejected = true; }
        check('schedule parser rejects ambiguous or invalid wall-clock time: ' . $invalid, $invalidRejected);
    }
} finally {
    foreach ($studioIds as $id) {
        Database::run('DELETE FROM content_posts WHERE id = ?', [$id]);
    }
    Database::run(Database::isPostgres() ? "SELECT set_config('TimeZone', ?, false)" : 'SET time_zone = ?', [$studioTimezone]);
    Settings::set('content_publish_time', $studioPostingTime, 'delivery-test');
}
