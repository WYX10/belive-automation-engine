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
        return ['ok' => true, 'video' => $file, 'model' => 'test-fixture', 'space' => 'offline-test'];
    });
    $done = Database::run('SELECT * FROM ai_video_jobs WHERE id = ?', [$successJob])->fetch();
    $post = $done['post_id'] ? Database::run('SELECT * FROM content_posts WHERE id = ?', [$done['post_id']])->fetch() : null;
    $meta = $post ? json_decode($post['creative_meta'], true) : [];
    check('AI generation completion atomically creates a video draft', $result && $done['status'] === 'completed' && $post['status'] === 'draft', json_encode($done));
    check('generated footage retains its model attribution and review label', !empty($meta['ai_generated_footage']) && !empty($meta['review_required']) && ($meta['video_model'] ?? '') === 'test-fixture');
    check('the generated draft includes an AI disclosure', $post && str_contains($post['caption'], 'AI-generated'));
    check('rendering the Wan reel skips additional photo model calls', (int) Database::run("SELECT COUNT(*) FROM ai_interactions WHERE message_kind = 'photo_touchup'")->fetchColumn() === $photoModelCalls);
    check('Wan draft attributes its local script separately from generated footage', $post && str_contains($post['generated_by_model'], 'inventory-template (no text API) + test-fixture'));
    $duplicates = 0;
    AiVideoJobs::processNext(static function () use (&$duplicates) { $duplicates++; return ['ok' => true]; });
    check('completed generation is not submitted twice', $duplicates === 0);
    if ($post) @unlink(APP_ROOT . '/public' . $post['video_url']);
    @unlink(APP_ROOT . '/storage/ai_video/job_' . $successJob . '/generated.mp4');
}
