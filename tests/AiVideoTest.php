<?php
declare(strict_types=1);

use App\Content\AiVideoJobs;
use App\Content\RoomVideoComposer;
use App\Core\Database;
use App\AI\Skills\CreateSkill;
use App\Models\Room;
use App\Properties\PropertyManager;
use App\Properties\PropertyReviewManager;

$aiProperty = PropertyManager::addProperty('AI Video Test', ['name' => 'AI Room', 'location' => 'Cheras', 'address' => 'AI Test']);
$aiProperty = PropertyReviewManager::review((int) $aiProperty['id'], 'approved', 'ai-video-test', 'Test fixture', (int) $aiProperty['review_version']);
$aiRoom = PropertyManager::addRoomForAdmin((int) $aiProperty['id'], ['room_code' => 'AI-TEST', 'name' => 'AI Test Room', 'room_type' => 'master', 'status' => 'available', 'price_monthly' => '800', 'price_6_month' => '780', 'price_12_month' => '760', 'referral_reward_points' => '60']);
$aiRoomId = (int) $aiRoom['id'];
Database::run('INSERT INTO room_images (room_id, image_path, sort_order) VALUES (?, ?, ?)',
    [$aiRoomId, '/assets/img/rooms/riamas-master-ensuite-1.jpg', 0]);
$savedContentModel = Database::run("SELECT model_key FROM ai_model_config WHERE phase = 'content_creation'")->fetchColumn();
if ($savedContentModel === false) {
    Database::run('INSERT INTO ai_model_config (phase, model_key) VALUES (?, ?)', ['content_creation', 'unavailable-test-model']);
} else {
    Database::run("UPDATE ai_model_config SET model_key = ? WHERE phase = 'content_creation'", ['unavailable-test-model']);
}
$savedMockMode = $_ENV['MOCK_AI'];
$_ENV['MOCK_AI'] = 'false';
try {
    $script = CreateSkill::inventoryVideoPromo($aiRoom, 'instagram', 'Invent a rooftop pool.');
    check('Wan scripting works with an unavailable text model and mock mode disabled', $script['scenes'] !== []
        && $script['model'] === 'inventory-template (no text API)');
    check('Wan inventory copy keeps its price, tenure and CTA without invented brief claims',
        str_contains($script['caption'], '760') && str_contains($script['caption'], '12-month')
        && str_contains($script['caption'], 'wa.') && !str_contains(json_encode($script), 'rooftop'));
    $scriptLog = Database::run("SELECT reasoning FROM ai_interactions WHERE message_kind = 'video_script' ORDER BY id DESC LIMIT 1")->fetchColumn();
    check('Wan script audit identifies local copy and the unapplied custom brief', str_contains($scriptLog, 'no text API requested')
        && str_contains($scriptLog, 'was not applied'));
} finally {
    $_ENV['MOCK_AI'] = $savedMockMode;
    if ($savedContentModel === false) Database::run("DELETE FROM ai_model_config WHERE phase = 'content_creation'");
    else Database::run("UPDATE ai_model_config SET model_key = ? WHERE phase = 'content_creation'", [$savedContentModel]);
}
$sourcePhoto = APP_ROOT . '/public/assets/img/rooms/riamas-master-ensuite-1.jpg';
$sourceHash = hash_file('sha256', $sourcePhoto);
$frameMethod = new ReflectionMethod(AiVideoJobs::class, 'inputFrame');
$photoModelCalls = (int) Database::run("SELECT COUNT(*) FROM ai_interactions WHERE message_kind = 'photo_touchup'")->fetchColumn();
$frame = $frameMethod->invoke(null, $aiRoom, null);
$frameSize = getimagesize($frame);
check('the model input is a private bounded PNG', str_starts_with($frame, APP_ROOT . '/storage/ai_video/') && $frameSize[2] === IMAGETYPE_PNG && max($frameSize[0], $frameSize[1]) <= 1280);
check('preparing a model input preserves the original room photograph', hash_file('sha256', $sourcePhoto) === $sourceHash);
check('preparing a Wan input skips the photo vision model', (int) Database::run("SELECT COUNT(*) FROM ai_interactions WHERE message_kind = 'photo_touchup'")->fetchColumn() === $photoModelCalls);
@unlink($frame);
Database::run('UPDATE room_images SET image_path = ? WHERE room_id = ?', ['/assets/img/rooms/placeholder.jpg', $aiRoomId]);
$placeholderRejected = false;
try { $frameMethod->invoke(null, $aiRoom, null); } catch (RuntimeException) { $placeholderRejected = true; }
Database::run('UPDATE room_images SET image_path = ? WHERE room_id = ?', ['/assets/img/rooms/riamas-master-ensuite-1.jpg', $aiRoomId]);
check('a placeholder photograph cannot be presented as a generated real room', $placeholderRejected);
$fixture = ['room_id' => $aiRoomId, 'platform' => 'instagram', 'payload' => json_encode($script), 'input_path' => 'test.png'];
$insertJob = static fn () => Database::insert('INSERT INTO ai_video_jobs (room_id, platform, payload, input_path) VALUES (?, ?, ?, ?)', array_values($fixture));

$failureJob = $insertJob();
$called = 0;
AiVideoJobs::processNext(static function () use (&$called) { $called++; return ['ok' => false, 'error' => 'quota']; });
$failed = Database::run('SELECT * FROM ai_video_jobs WHERE id = ?', [$failureJob])->fetch();
check('GPU quota failure is preserved as a failed job', $failed['status'] === 'failed' && $failed['error_code'] === 'quota');
AiVideoJobs::processNext(static function () use (&$called) { $called++; return ['ok' => true]; });
check('failed AI requests are not automatically retried', $called === 1);
$runtimeJob = $insertJob();
AiVideoJobs::processNext(static fn () => ['ok' => false, 'error' => 'provider_runtime', 'stage' => 'result',
    'exception_type' => 'AppError', 'message' => 'hf_PRIVATE room details', 'image' => '/private/room.png']);
$runtimeDetails = AiVideoJobs::diagnostics($runtimeJob);
check('hosted runtime failure retains a distinct error code', $runtimeDetails['job']['error_code'] === 'provider_runtime'
    && $runtimeDetails['job']['status'] === 'failed');
check('read-only diagnostics retain failure stage and exception type', $runtimeDetails['details_available']
    && $runtimeDetails['details'] === ['error' => 'provider_runtime', 'stage' => 'result', 'exception_type' => 'AppError']);
check('provider diagnostics omit messages, tokens and image paths', !str_contains(json_encode($runtimeDetails), 'PRIVATE')
    && !str_contains(json_encode($runtimeDetails), 'room.png'));
check('diagnostic file has private permissions', PHP_OS_FAMILY === 'Windows'
    || (fileperms(APP_ROOT . '/storage/ai_video/job_' . $runtimeJob . '/failure.json') & 0777) === 0600);
check('studio distinguishes provider runtime errors from authentication errors', AiVideoJobs::errorMessage('provider_runtime')
    !== AiVideoJobs::errorMessage('authentication') && str_contains(AiVideoJobs::errorMessage('provider_runtime'), 'runtime error'));
check('diagnostics discard unsupported categories and malformed details', AiVideoJobs::safeFailureDetails([
    'error' => 'hf_PRIVATE', 'stage' => '/private/path', 'exception_type' => 'contains private path!', 'http_status' => '401',
]) === []);
$previousVideoProvider = $_ENV['AI_VIDEO_PROVIDER'] ?? null;
$_ENV['AI_VIDEO_PROVIDER'] = 'huggingface';
check('mock mode cannot dispatch hosted GPU generation', !AiVideoJobs::available());
if ($previousVideoProvider === null) unset($_ENV['AI_VIDEO_PROVIDER']); else $_ENV['AI_VIDEO_PROVIDER'] = $previousVideoProvider;

$outsideJob = $insertJob();
AiVideoJobs::processNext(static fn () => ['ok' => true, 'video' => APP_ROOT . '/public/assets/img/rooms/videos/tour-master.mp4']);
$outside = Database::run('SELECT * FROM ai_video_jobs WHERE id = ?', [$outsideJob])->fetch();
check('a generated file outside the job cache is rejected', $outside['status'] === 'failed' && $outside['error_code'] === 'output');

if (RoomVideoComposer::isAvailable()) {
    $successJob = $insertJob();
    $photoModelCalls = (int) Database::run("SELECT COUNT(*) FROM ai_interactions WHERE message_kind = 'photo_touchup'")->fetchColumn();
    $result = AiVideoJobs::processNext(static function ($job, $work) {
        $file = $work . '/generated.mp4';
        // A local source clip tests the render boundary, not the model's visual quality.
        $source = APP_ROOT . '/public/assets/img/rooms/videos/tour-master.mp4';
        copy($source, $file);
        return ['ok' => true, 'video' => $file, 'model' => 'Wan2.2-I2V-A14B (first/last-frame)', 'space' => 'offline-test'];
    });
    $done = Database::run('SELECT * FROM ai_video_jobs WHERE id = ?', [$successJob])->fetch();
    $post = $done['post_id'] ? Database::run('SELECT * FROM content_posts WHERE id = ?', [$done['post_id']])->fetch() : null;
    $meta = $post ? json_decode($post['creative_meta'], true) : [];
    check('AI generation completion atomically creates a video draft', $result && $done['status'] === 'completed' && $post['status'] === 'draft', json_encode($done));
    check('generated footage retains its model attribution and review label', !empty($meta['ai_generated_footage']) && !empty($meta['review_required']) && ($meta['video_model'] ?? '') === 'Wan2.2-I2V-A14B (first/last-frame)');
    check('the generated draft includes an AI disclosure', $post && str_contains($post['caption'], 'AI-generated'));
    check('rendering the Wan reel skips additional photo model calls', (int) Database::run("SELECT COUNT(*) FROM ai_interactions WHERE message_kind = 'photo_touchup'")->fetchColumn() === $photoModelCalls);
    check('Wan draft attributes its local script separately from generated footage', $post && $post['generated_by_model'] === 'Wan2.2-I2V-A14B (first/last-frame)' && ($meta['script_model'] ?? '') === 'inventory-template (no text API)');
    $duplicates = 0;
    AiVideoJobs::processNext(static function () use (&$duplicates) { $duplicates++; return ['ok' => true]; });
    check('completed generation is not submitted twice', $duplicates === 0);
    if ($post) @unlink(APP_ROOT . '/public' . $post['video_url']);
    @unlink(APP_ROOT . '/storage/ai_video/job_' . $successJob . '/generated.mp4');
}

// The production model label previously exceeded content_posts.generated_by_model's limit.
if (Database::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
    $labelSqlstate = '';
    $pdo = Database::pdo();
    $pdo->beginTransaction();
    try {
        $pdo->exec('CREATE TEMPORARY TABLE ai_video_label_limit (label VARCHAR(60))');
        $statement = $pdo->prepare('INSERT INTO ai_video_label_limit (label) VALUES (?)');
        $statement->execute(['inventory-template (no text API) + Wan2.2-I2V-A14B (first/last-frame)']);
    } catch (PDOException $e) { $labelSqlstate = (string) $e->getCode(); }
    finally { $pdo->rollBack(); }
    check('the old production attribution reproduces PostgreSQL length error 22001', $labelSqlstate === '22001');
}
check('diagnostics expose a SQLSTATE without exposing a raw database message', AiVideoJobs::safeFailureDetails([
    'error' => 'database', 'stage' => 'database', 'exception_type' => 'PDOException', 'sqlstate' => '22001',
    'message' => 'private query and credentials',
]) === ['error' => 'database', 'stage' => 'database', 'exception_type' => 'PDOException', 'sqlstate' => '22001']);
$refusedProviderRecovery = false;
try { AiVideoJobs::recover($runtimeJob); } catch (RuntimeException) { $refusedProviderRecovery = true; }
check('recovery refuses failed provider jobs instead of submitting them again', $refusedProviderRecovery);
if (RoomVideoComposer::isAvailable()) {
    $recoverJob = $insertJob();
    Database::run("UPDATE ai_video_jobs SET status = 'failed', error_code = 'generation' WHERE id = ?", [$recoverJob]);
    $work = APP_ROOT . '/storage/ai_video/job_' . $recoverJob;
    if (!is_dir($work)) mkdir($work, 0700);
    if (!is_dir($work . '/download')) mkdir($work . '/download', 0700);
    @unlink($work . '/result.json');
    copy(APP_ROOT . '/public/assets/img/rooms/videos/tour-master.mp4', $work . '/download/generated.mp4');
    file_put_contents($work . '/failure.json', json_encode(['error' => 'generation', 'stage' => 'database', 'exception_type' => 'PDOException']));
    chmod($work . '/failure.json', 0600);
    $missingAttributionRefused = false;
    try { AiVideoJobs::recover($recoverJob); } catch (RuntimeException) { $missingAttributionRefused = true; }
    check('legacy recovery requires explicit original Space attribution', $missingAttributionRefused
        && Database::run('SELECT status FROM ai_video_jobs WHERE id = ?', [$recoverJob])->fetchColumn() === 'failed');
    $countBefore = (int) Database::run('SELECT COUNT(*) FROM content_posts')->fetchColumn();
    // Offline fixture simulates the production cache; no provider client is called by recovery.
    $recoveredPost = AiVideoJobs::recover($recoverJob, 'multimodalart/wan-2-2-first-last-frame');
    $recovered = Database::run('SELECT * FROM content_posts WHERE id = ?', [$recoveredPost])->fetch();
    check('legacy recovery saves the cached clip as a draft without a configured provider', $recovered['status'] === 'draft'
        && $recovered['generated_by_model'] === 'Wan2.2-I2V-A14B (first/last-frame)');
    check('local recovery preserves the cached clip and a private result manifest', is_file($work . '/download/generated.mp4')
        && is_file($work . '/result.json') && (fileperms($work . '/result.json') & 0777) === 0600);
    check('repeated recovery returns the same post and creates no duplicate draft', AiVideoJobs::recover($recoverJob) === $recoveredPost
        && (int) Database::run('SELECT COUNT(*) FROM content_posts')->fetchColumn() === $countBefore + 1);
    // Test manifest-based recovery, including a future model name longer than the database label.
    $manifestJob = $insertJob();
    Database::run("UPDATE ai_video_jobs SET status = 'failed', error_code = 'database' WHERE id = ?", [$manifestJob]);
    $manifestWork = APP_ROOT . '/storage/ai_video/job_' . $manifestJob;
    if (!is_dir($manifestWork)) mkdir($manifestWork, 0700);
    copy($work . '/download/generated.mp4', $manifestWork . '/generated.mp4');
    copy($work . '/failure.json', $manifestWork . '/failure.json');
    $longModel = str_repeat('video-model-', 8);
    file_put_contents($manifestWork . '/result.json', json_encode(['video' => $manifestWork . '/generated.mp4', 'model' => $longModel, 'space' => 'offline-test']));
    $manifestPost = AiVideoJobs::recover($manifestJob);
    $manifestDraft = Database::run('SELECT * FROM content_posts WHERE id = ?', [$manifestPost])->fetch();
    $manifestMeta = json_decode($manifestDraft['creative_meta'], true);
    check('saved-result recovery bounds display labels and preserves full model provenance', mb_strlen($manifestDraft['generated_by_model']) === 60
        && $manifestMeta['video_model'] === $longModel && $manifestMeta['script_model'] === $script['model']);
    $stalledJob = $insertJob();
    Database::run("UPDATE ai_video_jobs SET status = 'running' WHERE id = ?", [$stalledJob]);
    $stalledWork = APP_ROOT . '/storage/ai_video/job_' . $stalledJob;
    if (!is_dir($stalledWork)) mkdir($stalledWork, 0700);
    @unlink($stalledWork . '/result.json');
    copy($work . '/download/generated.mp4', $stalledWork . '/generated.mp4');
    copy($work . '/failure.json', $stalledWork . '/failure.json');
    $runningRefused = false;
    try { AiVideoJobs::recover($stalledJob, 'multimodalart/wan-2-2-first-last-frame'); } catch (RuntimeException) { $runningRefused = true; }
    check('normal recovery never changes a running job', $runningRefused
        && Database::run('SELECT status FROM ai_video_jobs WHERE id = ?', [$stalledJob])->fetchColumn() === 'running');
    $lockCfg = require APP_ROOT . '/config/database.php';
    $lockCfg['name'] = (string) Database::run('SELECT DATABASE()')->fetchColumn();
    $otherWorker = Database::connect($lockCfg);
    $held = Database::acquireLock('belive_ai_video_generation', 0, $otherWorker);
    $activeWorkerRefused = false;
    try { AiVideoJobs::recover($stalledJob, 'multimodalart/wan-2-2-first-last-frame', true); }
    catch (RuntimeException $e) { $activeWorkerRefused = str_contains($e->getMessage(), 'busy'); }
    finally { if ($held) Database::releaseLock('belive_ai_video_generation', $otherWorker); }
    check('stalled recovery refuses an active worker and preserves its job state', $held && $activeWorkerRefused
        && Database::run('SELECT status FROM ai_video_jobs WHERE id = ?', [$stalledJob])->fetchColumn() === 'running');
    $stalledCountBefore = (int) Database::run('SELECT COUNT(*) FROM content_posts')->fetchColumn();
    $stalledPost = AiVideoJobs::recover($stalledJob, 'multimodalart/wan-2-2-first-last-frame', true);
    $stalledDraft = Database::run('SELECT * FROM content_posts WHERE id = ?', [$stalledPost])->fetch();
    check('explicit stalled recovery uses cached footage to complete one draft', $stalledDraft['status'] === 'draft'
        && Database::run('SELECT status FROM ai_video_jobs WHERE id = ?', [$stalledJob])->fetchColumn() === 'completed');
    check('repeating stalled recovery creates no duplicate post', AiVideoJobs::recover($stalledJob, null, true) === $stalledPost
        && (int) Database::run('SELECT COUNT(*) FROM content_posts')->fetchColumn() === $stalledCountBefore + 1);
    Database::run("UPDATE ai_video_jobs SET status = 'running' WHERE id = ?", [$runtimeJob]);
    $runningProviderRefused = false;
    try { AiVideoJobs::recover($runtimeJob, 'multimodalart/wan-2-2-first-last-frame', true); } catch (RuntimeException) { $runningProviderRefused = true; }
    check('stalled recovery requires local-failure evidence and never resets a provider failure', $runningProviderRefused
        && Database::run('SELECT status FROM ai_video_jobs WHERE id = ?', [$runtimeJob])->fetchColumn() === 'running');
    Database::run("UPDATE ai_video_jobs SET status = 'failed' WHERE id = ?", [$runtimeJob]);
    @unlink(APP_ROOT . '/public' . $stalledDraft['video_url']);
    @unlink(APP_ROOT . '/public' . $recovered['video_url']);
    @unlink(APP_ROOT . '/public' . $manifestDraft['video_url']);
}
