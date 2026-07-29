<?php

declare(strict_types=1);

/**
 * The content pipeline's two admin-facing promises:
 *   1. every AI caption carries a tap-to-chat WhatsApp link, even when the
 *      model forgets to include the one it was given,
 *   2. the admin can rewrite that caption right up until it publishes — and
 *      never after.
 */

use App\AI\Skills\CreateSkill;
use App\Core\Database;
use App\Core\Settings;
use App\Integrations\Social\SocialPublishManager;
use App\Integrations\WhatsApp\WhatsAppLink;
use App\Properties\PropertyManager;
use App\Properties\PropertyReviewManager;

Settings::set('social_wa_number', '60123456789', 'caption-test');
Settings::set('content_wa_prefill', 'Hi beLive! I saw your {platform} post about {room} in {area} — still available?', 'caption-test');

$captionOwner = 'Caption Link Test Owner';
$captionProperty = PropertyManager::addProperty($captionOwner, [
    'name' => 'Caption Link Residence',
    'location' => 'Setapak',
    'address' => '9 Caption Road, Setapak',
]);
$captionProperty = PropertyReviewManager::review(
    (int) $captionProperty['id'],
    'approved',
    'caption-test-admin',
    'Approved so the caption test can post about it.',
    (int) $captionProperty['review_version']
);
$captionRoom = PropertyManager::addRoomForAdmin((int) $captionProperty['id'], [
    'room_code' => 'CLR-01',
    'name' => 'Caption Middle Room',
    'room_type' => 'middle',
    'status' => 'available',
    'price_monthly' => '780',
    'price_6_month' => '740',
    'price_12_month' => '700',
    'referral_reward_points' => '60',
]);

// ---- the link itself -------------------------------------------------------
$link = CreateSkill::captionWhatsappLink($captionRoom, 'facebook');
check('caption link points at the configured WhatsApp number',
    str_starts_with($link, 'https://wa.me/60123456789?text='), $link);
check('caption link prefills the room and area the reader saw',
    str_contains(rawurldecode($link), 'Caption Middle Room')
    && str_contains(rawurldecode($link), 'Setapak')
    && str_contains(rawurldecode($link), 'Facebook'), rawurldecode($link));

// ---- generated caption -----------------------------------------------------
// The offline stub never writes a link, which is exactly the case that must
// still reach the customer with one.
$caption = CreateSkill::socialCaption($captionRoom, 'facebook');
check('an AI caption always ships with the WhatsApp link', str_contains($caption['text'], $link), $caption['text']);
check('...appended once, not duplicated', substr_count($caption['text'], $link) === 1, $caption['text']);

// The campaign tag is a submission requirement, so it gets the same treatment
// as the link: enforced on the way out, never left to the model to remember.
check('an AI caption always carries the campaign hashtag',
    str_contains($caption['text'], CONTENT_REQUIRED_HASHTAG), $caption['text']);
check('...exactly once, whoever wrote it',
    substr_count($caption['text'], CONTENT_REQUIRED_HASHTAG) === 1, $caption['text']);

Database::run(
    "INSERT INTO content_posts (platform, room_id, caption, status, generated_by_model, generated_via)
     VALUES ('facebook', ?, ?, 'draft', ?, 'manual')",
    [(int) $captionRoom['id'], $caption['text'], $caption['model']]
);
$postId = (int) Database::pdo()->lastInsertId();
$post = Database::run('SELECT * FROM content_posts WHERE id = ?', [$postId])->fetch();

// ---- admin edits before it posts -------------------------------------------
$edited = SocialPublishManager::updateCaption(
    $postId,
    "Rewritten by the admin — zero deposit, move in this week.\n\nWhatsApp us: $link",
    'caption-test-admin',
    (int) $post['review_version']
);
check('admin can rewrite the caption of a draft',
    str_starts_with($edited['caption'], 'Rewritten by the admin'), $edited['caption']);
check('editing bumps the review version so a stale approval cannot slip through',
    (int) $edited['review_version'] === (int) $post['review_version'] + 1);

$staleRejected = false;
try {
    SocialPublishManager::updateCaption($postId, 'Second editor, stale page.', 'other-admin', (int) $post['review_version']);
} catch (RuntimeException) {
    $staleRejected = true;
}
check('a second editor working from a stale page is refused', $staleRejected);

$emptyRejected = false;
try {
    SocialPublishManager::updateCaption($postId, "   \n ", 'caption-test-admin', (int) $edited['review_version']);
} catch (InvalidArgumentException) {
    $emptyRejected = true;
}
check('an empty caption is refused', $emptyRejected);

$tooLongRejected = false;
try {
    SocialPublishManager::updateCaption($postId, str_repeat('x', CONTENT_CAPTION_MAX + 1), 'caption-test-admin', (int) $edited['review_version']);
} catch (InvalidArgumentException) {
    $tooLongRejected = true;
}
check('a caption past the platform ceiling is refused', $tooLongRejected);

// ---- the edit is what actually goes out ------------------------------------
$published = SocialPublishManager::approveAndPublish($postId, 'caption-test-admin', (int) $edited['review_version']);
check('approval publishes (simulated without a live credential)',
    $published['status'] === 'posted' && $published['publish_status'] === 'simulated', json_encode($published['publish_status']));
check('the published caption is the admin\'s edit, not the AI draft',
    str_starts_with($published['caption'], 'Rewritten by the admin'), $published['caption']);

$postedEditRejected = false;
try {
    SocialPublishManager::updateCaption($postId, 'Too late to change this.', 'caption-test-admin', (int) $published['review_version']);
} catch (RuntimeException) {
    $postedEditRejected = true;
}
check('a caption that already went out can no longer be edited', $postedEditRejected);

// ---- no number configured --------------------------------------------------
$envNumber = $_ENV['EVE_WA_NUMBER'] ?? null;
Settings::set('social_wa_number', '', 'caption-test');
$_ENV['EVE_WA_NUMBER'] = '';
check('with no number configured the shared fallback link is still handed out',
    WhatsAppLink::to('anything') === 'https://wa.link/hg32ho');

if ($envNumber === null) {
    unset($_ENV['EVE_WA_NUMBER']);
} else {
    $_ENV['EVE_WA_NUMBER'] = $envNumber;
}
