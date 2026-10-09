<?php

declare(strict_types=1);

/**
 * The promo video half of the content studio:
 *   1. a script is always renderable — out-of-contract model output is clamped,
 *      unusable output falls back to the room's own metrics, and the caption
 *      still carries the tap-to-chat link,
 *   2. the render is the room's real photography, not generated imagery, and it
 *      produces a real 9:16 file (skipped where ffmpeg isn't installed),
 *   3. each platform publishes a video down its video route, and a video draft
 *      with nothing rendered fails as a video rather than quietly posting the
 *      poster photo,
 *   4. BeLive's mascot reacts to what the copy says and lands on both kinds of
 *      post, without the owner's original photography ever being written to.
 */

use App\AI\Skills\CreateSkill;
use App\Content\MascotLibrary;
use App\Content\MascotNarrator;
use App\Content\PhotoPostDrafter;
use App\Content\PromoVideoDrafter;
use App\Content\RoomVideoComposer;
use App\Core\Database;
use App\Core\Settings;
use App\Models\Room;
use App\Integrations\Social\FacebookPublisher;
use App\Integrations\Social\InstagramPublisher;
use App\Integrations\Social\SocialPublishManager;
use App\Integrations\Social\TikTokPublisher;
use App\Models\ApiCredential;
use App\Properties\PropertyManager;
use App\Properties\PropertyReviewManager;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

Settings::set('social_wa_number', '60123456789', 'promo-video-test');

$videoProperty = PropertyManager::addProperty('Promo Video Test Owner', [
    'name' => 'Promo Reel Residence',
    'location' => 'Cheras',
    'address' => '12 Reel Road, Cheras',
]);
$videoProperty = PropertyReviewManager::review(
    (int) $videoProperty['id'],
    'approved',
    'promo-video-admin',
    'Approved so the promo video test can feature it.',
    (int) $videoProperty['review_version']
);
$videoRoom = PropertyManager::addRoomForAdmin((int) $videoProperty['id'], [
    'room_code' => 'PRR-01',
    'name' => 'Reel Master Room',
    'room_type' => 'master',
    'status' => 'available',
    'price_monthly' => '900',
    'price_6_month' => '860',
    'price_12_month' => '820',
    'referral_reward_points' => '60',
]);
$videoRoomId = (int) $videoRoom['id'];

// Real photography that ships with the repo — the composer only ever renders
// media already attached to the room, so the test attaches the real thing.
foreach (['/assets/img/rooms/riamas-master-ensuite-1.jpg', '/assets/img/rooms/riamas-medium-1.jpg'] as $order => $path) {
    Database::run('INSERT INTO room_images (room_id, image_path, sort_order) VALUES (?, ?, ?)', [$videoRoomId, $path, $order]);
}

// ---- the script -------------------------------------------------------------
$promo = CreateSkill::videoPromo($videoRoom, 'instagram', null, 2);

check('a promo video script comes back with scenes', $promo['scenes'] !== [], json_encode($promo['scenes']));
check('the video caption carries the tap-to-chat WhatsApp link',
    str_contains($promo['caption'], 'https://wa.me/60123456789'), $promo['caption']);
check('the video caption carries the campaign hashtag',
    str_contains($promo['caption'], CONTENT_REQUIRED_HASHTAG), $promo['caption']);

$sceneShapeOk = true;
foreach ($promo['scenes'] as $scene) {
    $sceneShapeOk = $sceneShapeOk
        && $scene['headline'] !== ''
        && mb_strlen($scene['headline']) <= 40
        && mb_strlen($scene['sub']) <= 60
        && $scene['seconds'] >= CONTENT_VIDEO_SCENE_SECONDS['min']
        && $scene['seconds'] <= CONTENT_VIDEO_SCENE_SECONDS['max'];
}
check('every scene fits the frame and the clock', $sceneShapeOk, json_encode($promo['scenes']));
check('a script never runs longer than a short-form reel should',
    count($promo['scenes']) <= CONTENT_VIDEO_SCENE_MAX && RoomVideoComposer::duration($promo['scenes']) <= 30.0,
    (string) RoomVideoComposer::duration($promo['scenes']));

// Cross-fades overlap, so the reel is shorter than the sum of its scenes.
check('duration accounts for the cross-fade overlap',
    RoomVideoComposer::duration([['seconds' => 4.0], ['seconds' => 4.0], ['seconds' => 4.0]]) === 11.0);

// ---- the mascot -------------------------------------------------------------
check('the mascot artwork ships with the app', MascotLibrary::isAvailable());
check('every named pose is actually on disk',
    array_filter(MascotLibrary::POSES, static fn (string $p): bool => MascotLibrary::file($p) === null) === [],
    implode(', ', array_filter(MascotLibrary::POSES, static fn (string $p): bool => MascotLibrary::file($p) === null)));
check('an unknown pose is refused rather than guessed at', MascotLibrary::file('not-a-pose') === null);

// The pose is read off what the scene says, so the character reacts to the copy.
check('the opening scene waves before it sells', MascotLibrary::forScene(0, 'Master room in Cheras') === 'waving');
check('the closing card gets the megaphone', MascotLibrary::forScene(3, 'anything at all', true) === 'megaphone');
check('a price line gets the trophy', MascotLibrary::forScene(1, 'RM 820/mo on a 12-month stay') === 'trophy');
check('a zero-deposit line gets the fist pump', MascotLibrary::forScene(2, 'Zero deposit, move in now') === 'cheering');
check('a wifi line gets the laptop', MascotLibrary::forScene(2, 'Fast WiFi for late-night study') === 'laptop');
// Cues are ordered, so a line that could take two poses takes the first — the
// benefit BeLive leads with, not whichever pattern happened to be written last.
check('a line with competing cues resolves to one pose, predictably',
    MascotLibrary::forScene(2, 'WiFi and weekly cleaning') === 'zen');
check('a scene with no cue still gets a pose', in_array(MascotLibrary::forScene(2, 'A quiet corner unit'), MascotLibrary::POSES, true));
check('the same scene always picks the same pose',
    MascotLibrary::forScene(2, 'A quiet corner unit') === MascotLibrary::forScene(2, 'A quiet corner unit'));

// ---- branding a photo post --------------------------------------------------
$photoOriginalHash = hash_file('sha256', APP_ROOT . '/public/assets/img/rooms/riamas-master-ensuite-1.jpg');
$photoDraft = PhotoPostDrafter::draft($videoRoom, 'facebook', 'Zero deposit push.');
$brandedPost = Database::run('SELECT * FROM content_posts WHERE id = ?', [$photoDraft['post_id']])->fetch();

check('a photo post is branded with the mascot', $photoDraft['branded'] === true, (string) $photoDraft['image_url']);
check('the photo post publishes the branded copy, not the raw room photo',
    str_starts_with((string) $brandedPost['image_url'], '/assets/img/uploads/branded/'), (string) $brandedPost['image_url']);
check('the branded copy is a real image of the same shape as the original', (static function () use ($brandedPost): bool {
    $branded = @getimagesize(APP_ROOT . '/public' . $brandedPost['image_url']);
    $original = @getimagesize(APP_ROOT . '/public/assets/img/rooms/riamas-master-ensuite-1.jpg');

    return $branded !== false && $original !== false && $branded[0] === $original[0] && $branded[1] === $original[1];
})());
check('the owner\'s original photography is left exactly as uploaded',
    Room::photoUrls($videoRoomId)[0] === '/assets/img/rooms/riamas-master-ensuite-1.jpg'
    && hash_file('sha256', APP_ROOT . '/public/assets/img/rooms/riamas-master-ensuite-1.jpg') === $photoOriginalHash);
check('the photo caption carries the campaign hashtag too',
    str_contains((string) $brandedPost['caption'], CONTENT_REQUIRED_HASHTAG), (string) $brandedPost['caption']);
$photoCreative = json_decode((string) $brandedPost['creative_meta'], true);
check('campaign photos include measured image correction and integrated host placement',
    ($photoCreative['style'] ?? '') === 'room_story' && isset($photoCreative['enhancement']['brightness'])
    && in_array($photoCreative['placement'] ?? '', ['left', 'right'], true));
check('the corrected campaign copy also changes room pixels clear of the mascot and typography', (static function () use ($brandedPost): bool {
    $original = imagecreatefromjpeg(APP_ROOT . '/public/assets/img/rooms/riamas-master-ensuite-1.jpg');
    $result = imagecreatefromjpeg(APP_ROOT . '/public' . $brandedPost['image_url']);
    $x = (int) (imagesx($original) * 0.5);
    $y = (int) (imagesy($original) * 0.4);
    $changed = imagecolorat($original, $x, $y) !== imagecolorat($result, $x, $y);
    imagedestroy($original);
    imagedestroy($result);
    return $changed;
})());
if (str_starts_with((string) $brandedPost['image_url'], '/assets/img/uploads/branded/')) {
    @unlink(APP_ROOT . '/public' . $brandedPost['image_url']);
}

// A room with no photography has nothing to brand, and that is not a failure.
check('a photoless room still drafts a caption post', (static function () use ($videoProperty): bool {
    $room = App\Properties\PropertyManager::addRoomForAdmin((int) $videoProperty['id'], [
        'room_code' => 'PRR-03', 'name' => 'Unbranded Room', 'room_type' => 'single', 'status' => 'available',
        'price_monthly' => '600', 'price_6_month' => '580', 'price_12_month' => '560', 'referral_reward_points' => '40',
    ]);
    $draft = PhotoPostDrafter::draft($room, 'facebook');

    return $draft['post_id'] > 0 && $draft['branded'] === false && $draft['image_url'] === null;
})());

// ---- the render -------------------------------------------------------------
if (RoomVideoComposer::isAvailable()) {
    $draft = PromoVideoDrafter::draft($videoRoom, 'instagram', 'Push the zero deposit angle.');
    $renderedFile = APP_ROOT . '/public' . $draft['video_url'];

    check('the drafter renders a real video file', is_file($renderedFile) && filesize($renderedFile) > 10240, $draft['video_url']);
    check('the reel is stored under the uploads folder, like every other room media',
        str_starts_with($draft['video_url'], '/assets/img/uploads/videos/'), $draft['video_url']);

    $post = Database::run('SELECT * FROM content_posts WHERE id = ?', [$draft['post_id']])->fetch();
    check('the draft is a video post awaiting the same approval a photo post gets',
        $post['media_kind'] === 'video' && $post['status'] === 'draft' && $post['video_url'] === $draft['video_url']);
    check('the poster frame is the room\'s own first photo',
        $post['image_url'] === '/assets/img/rooms/riamas-master-ensuite-1.jpg', (string) $post['image_url']);

    $scenes = json_decode((string) $post['video_script'], true);
    $videoCreative = json_decode((string) $post['creative_meta'], true);
    check('the rendered video records an animated mascot guided tour', ($videoCreative['style'] ?? '') === 'mascot_guided_tour' && !empty($videoCreative['animated']));
    check('every tour scene stores the host narration and camera direction', count(array_filter($scenes, static fn (array $s): bool => !empty($s['narration']) && !empty($s['presenter_action']) && !empty($s['camera']))) === count($scenes));
    if (MascotNarrator::isAvailable()) {
        exec(escapeshellarg((string) RoomVideoComposer::binary()) . ' -hide_banner -i ' . escapeshellarg($renderedFile)
            . ' -vn -af volumedetect -f null - 2>&1', $volumeOutput, $volumeCode);
        preg_match('/max_volume:\s*(-?[\d.]+) dB/', implode(' ', $volumeOutput), $volumeMatch);
        check('the mascot introduction contains audible speech rather than a silent audio bed',
            !empty($videoCreative['narrated']) && $volumeCode === 0 && isset($volumeMatch[1]) && (float) $volumeMatch[1] > -40.0,
            implode(' ', $volumeMatch));
    }
    check('the shot list is stored with the post so the studio can show it', is_array($scenes) && $scenes !== []);
    check('the studio, not the model, guarantees the closing WhatsApp card',
        !empty($scenes[count($scenes) - 1]['cta'])
        && str_contains((string) $scenes[count($scenes) - 1]['sub'], 'WhatsApp'),
        json_encode($scenes[count($scenes) - 1] ?? null));

    // ffprobe reads what was actually written — the format claim is checked, not assumed.
    $ffprobe = preg_replace('/ffmpeg(\.exe)?$/i', 'ffprobe$1', (string) RoomVideoComposer::binary());
    exec(
        escapeshellarg((string) $ffprobe) . ' -v error -select_streams v:0 -show_entries stream=width,height'
        . ' -show_entries format=duration -of default=nw=1:nk=1 ' . escapeshellarg($renderedFile) . ' 2>&1',
        $probe
    );
    $probed = array_values(array_filter(array_map('trim', $probe)));
    check('the rendered file is a 1080x1920 vertical video',
        ($probed[0] ?? '') === (string) CONTENT_VIDEO_WIDTH && ($probed[1] ?? '') === (string) CONTENT_VIDEO_HEIGHT,
        implode(' x ', array_slice($probed, 0, 2)));
    check('...and it actually runs for the length the studio advertises',
        abs((float) ($probed[2] ?? 0) - $draft['seconds']) < 1.5,
        ($probed[2] ?? '?') . ' vs ' . $draft['seconds']);

    @unlink($renderedFile);
} else {
    echo "  – ffmpeg not installed; render checks skipped (script and publish checks still ran)\n";
}

// A room with nothing attached cannot be advertised with footage of some other
// unit — the render refuses rather than substituting stock media.
$emptyRoom = PropertyManager::addRoomForAdmin((int) $videoProperty['id'], [
    'room_code' => 'PRR-02',
    'name' => 'Photoless Room',
    'room_type' => 'single',
    'status' => 'available',
    'price_monthly' => '600',
    'price_6_month' => '580',
    'price_12_month' => '560',
    'referral_reward_points' => '40',
]);
$photolessRefused = false;
try {
    RoomVideoComposer::render($emptyRoom, [['headline' => 'No shots here', 'sub' => '', 'seconds' => 3.0]]);
} catch (RuntimeException) {
    $photolessRefused = true;
}
check('a room with no photography cannot have a promo video rendered', $photolessRefused);

// ---- publishing a video -----------------------------------------------------
/**
 * @param array<int, Response> $responses
 * @return array{0: Client, 1: ArrayObject}
 */
$recordingClient = static function (array $responses): array {
    $stack = HandlerStack::create(new MockHandler($responses));
    $sent = new ArrayObject();
    $stack->push(Middleware::history($sent));

    return [new Client(['handler' => $stack]), $sent];
};
$json = static fn (array $body): Response => new Response(200, ['Content-Type' => 'application/json'], json_encode($body));
$bodyOf = static fn (ArrayObject $sent, int $i): array => json_decode((string) $sent[$i]['request']->getBody(), true) ?? [];

// Instagram: a video is a REELS container, never an image one. No page_id on
// the credential, so no Page-token exchange gets in front of the container call.
$igCredentialId = ApiCredential::store('meta_graph', 'promo video ig', 'PAGE-TOKEN', ['ig_user_id' => 'IG-USER-9']);
[$igClient, $igSent] = $recordingClient([
    $json(['id' => 'REEL-CONTAINER']),
    $json(['status_code' => 'FINISHED']),
    $json(['id' => 'IG-REEL-9']),
]);
$igResult = (new InstagramPublisher($igClient, [0, 0, 0]))
    ->publish('Reel caption', 'https://belive.example/assets/img/uploads/videos/reel.mp4', 'video');
$igContainer = $bodyOf($igSent, 0);
check('Instagram publishes a promo video as a REELS container',
    ($igContainer['media_type'] ?? '') === 'REELS'
    && ($igContainer['video_url'] ?? '') === 'https://belive.example/assets/img/uploads/videos/reel.mp4'
    && !isset($igContainer['image_url']),
    json_encode($igContainer));
check('...and returns the reel\'s real post id', ($igResult['external_id'] ?? '') === 'IG-REEL-9');
ApiCredential::update($igCredentialId, ['is_active' => 0]);

// Facebook: the Page video edge, whose caption field is 'description'. The
// first response is the stored token being exchanged for a Page token.
$fbCredentialId = ApiCredential::store('meta_graph', 'promo video page', 'USER-TOKEN', ['page_id' => '55501']);
[$fbClient, $fbSent] = $recordingClient([
    $json(['access_token' => 'PAGE-TOKEN']),
    $json(['id' => 'FB-VIDEO-3']),
]);
$fbResult = (new FacebookPublisher($fbClient))
    ->publish('Reel caption', 'https://belive.example/assets/img/uploads/videos/reel.mp4', 'video');
check('Facebook posts a promo video on the Page video edge',
    str_contains((string) $fbSent[1]['request']->getUri(), '/55501/videos'), (string) $fbSent[1]['request']->getUri());
check('...with the caption in the field the video edge reads, and the video pulled by URL',
    ($bodyOf($fbSent, 1)['description'] ?? '') === 'Reel caption'
    && ($bodyOf($fbSent, 1)['file_url'] ?? '') === 'https://belive.example/assets/img/uploads/videos/reel.mp4',
    json_encode($bodyOf($fbSent, 1)));
check('...and hands back the video id', ($fbResult['external_id'] ?? '') === 'FB-VIDEO-3');

// Leave no active meta_graph credential behind for the later test files.
ApiCredential::update($fbCredentialId, ['is_active' => 0]);

// TikTok with no credential: the dry run must log the VIDEO endpoint, not the photo one.
Database::run("DELETE FROM ai_activity_log WHERE action = 'social_dry_run_publish'");
$ttResult = (new TikTokPublisher())->publish('Reel caption', 'https://belive.example/reel.mp4', 'video');
$logged = json_decode((string) Database::run(
    "SELECT detail FROM ai_activity_log WHERE action = 'social_dry_run_publish' ORDER BY id DESC LIMIT 1"
)->fetchColumn(), true) ?: [];
check('TikTok simulates a promo video against its video init endpoint',
    ($logged['endpoint'] ?? '') === '/post/publish/video/init/', json_encode($logged['endpoint'] ?? null));
check('...pulling the video by URL, with no photo fields left over',
    ($logged['payload']['source_info']['video_url'] ?? '') === 'https://belive.example/reel.mp4'
    && !isset($logged['payload']['source_info']['photo_images']),
    json_encode($logged['payload']['source_info'] ?? null));
check('...and is badged as a dry run, never as a real post', $ttResult['dry_run'] === true);

// Media is a precondition on both, and the refusal has to name the video.
foreach ([['Instagram', new InstagramPublisher()], ['TikTok', new TikTokPublisher()]] as [$name, $publisher]) {
    $refused = '';
    try {
        $publisher->publish('Reel caption', null, 'video');
    } catch (RuntimeException $e) {
        $refused = $e->getMessage();
    }
    check("$name refuses a video post with nothing rendered", str_contains($refused, 'rendered video'), $refused);
}

// ---- a video draft whose render is missing ----------------------------------
Database::run(
    "INSERT INTO content_posts (platform, media_kind, room_id, caption, status, generated_by_model, generated_via, image_url)
     VALUES ('instagram', 'video', ?, 'Caption with no reel behind it', 'draft', 'test', 'manual', ?)",
    [$videoRoomId, '/assets/img/rooms/riamas-master-ensuite-1.jpg']
);
$brokenId = (int) Database::pdo()->lastInsertId();
$broken = Database::run('SELECT * FROM content_posts WHERE id = ?', [$brokenId])->fetch();

check('a video post resolves its video, not its poster frame',
    SocialPublishManager::resolveMediaUrl($broken) === null
    && SocialPublishManager::resolveImageUrl($broken) !== null);

$brokenResult = SocialPublishManager::approveAndPublish($brokenId, 'promo-video-admin', (int) $broken['review_version']);
check('approving a video draft with no rendered file fails honestly instead of posting the photo',
    $brokenResult['publish_status'] === 'failed'
    && str_contains((string) $brokenResult['publish_error'], 'no rendered video'),
    (string) $brokenResult['publish_error']);
