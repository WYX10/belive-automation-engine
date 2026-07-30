<?php

declare(strict_types=1);

/**
 * "Sending you the photos now 📸" — and no photo arrives.
 *
 * Eve only ever attached images for send_photos_first, the one-time
 * show-the-room-before-the-price opener. That flag is deliberately suppressed
 * once pricing has come up, and the suppression was unconditional: from the
 * first price question onward there was NO path left that could send an image,
 * however many times the customer asked. The reply text still said photos were
 * on the way, because CreateSkill was never told the send had been cancelled.
 *
 * The same conversation exposed the other half: when a model's JSON came back
 * unreadable (a Claude model without JSON-mode prefill, or an answer cut off by
 * the token budget) DecideSkill silently substituted blank defaults — throwing
 * away the learned rules injected into that very prompt — and the only trace
 * was one line of reasoning in the admin log.
 *
 * What is asserted here: a photo request is honoured whatever else the
 * conversation has covered, a reply never claims a photo it did not send, and a
 * decision that could not be read is recorded as a skipped-rules failure rather
 * than passed off as a decision.
 */

use App\AI\Skills\SkillSupport;
use App\Core\Database;
use App\Models\Room;
use App\Pipeline\Conversion\ConversationManager;
use App\Properties\PropertyManager;
use App\Properties\PropertyReviewManager;

// Capturing WhatsApp stub — records texts and images separately.
$photoWa = new class extends \App\Integrations\WhatsApp\WhatsAppClient {
    public array $texts = [];
    public array $images = [];

    public function sendText(string $toWaPhone, string $text): array
    {
        $this->texts[] = $text;

        return ['message_id' => 'test.' . count($this->texts), 'dry_run' => true];
    }

    public function sendImage(string $toWaPhone, string $imageUrl, string $caption = ''): array
    {
        $this->images[] = $imageUrl;

        return ['message_id' => 'test.img.' . count($this->images), 'dry_run' => true];
    }
};

// ---- a room the customer can actually be shown ------------------------------
$photoOwner = 'Photo Request Owner';
$photoProperty = PropertyManager::addProperty($photoOwner, [
    'name'     => 'Warisan Heights',
    'location' => 'Kota Warisan',
    'address'  => '11 Jalan Warisan, Kota Warisan',
]);
$photoProperty = PropertyReviewManager::review(
    (int) $photoProperty['id'],
    'approved',
    'photo-admin',
    'Approved so the room is live inventory.',
    (int) $photoProperty['review_version']
);
$photoRoom = PropertyManager::addRoom($photoOwner, [
    'property_id'            => $photoProperty['id'],
    'room_code'              => 'RM-111',
    'name'                   => 'RM-111',
    'room_type'              => 'middle',
    'status'                 => 'available',
    'price_monthly'          => '520',
    'price_6_month'          => '490',
    'price_12_month'         => '455',
    'referral_reward_points' => '40',
    'available_from'         => '2026-08-01',
    'description'            => 'Middle room with a window.',
]);
$photoRoomId = (int) $photoRoom['id'];

foreach (['a', 'b', 'c', 'd'] as $i => $suffix) {
    Database::run(
        'INSERT INTO room_images (room_id, image_path, sort_order) VALUES (?, ?, ?)',
        [$photoRoomId, "/assets/img/rooms/rm111-$suffix.jpg", $i]
    );
}

check('the room has photos to send', count(Room::photoUrls($photoRoomId)) === 4);

// ---- 1. the reported conversation, in order ---------------------------------
$photoPhone = '60129990888';
$photoManager = new ConversationManager($photoWa);

$say = static function (string $text) use ($photoManager, $photoPhone): void {
    static $n = 0;
    $photoManager->handleInbound([
        'wa_phone'   => $photoPhone,
        'name'       => 'Hui Wen',
        'text'       => $text,
        'message_id' => 'wamid.photoreq' . ++$n,
        'timestamp'  => time(),
    ]);
};

$say('looking for a middle room in Kota Warisan, budget RM455, staying 12 months');
$say('how much is it?');

// Whether the opener sent photos depends on the learned rules in play, so what
// matters is the turn under test: the photo request that follows the pricing.
$beforeRequest = count($photoWa->images);
$say('can i see the photos please?');
$thisTurn = array_slice($photoWa->images, $beforeRequest);

check(
    'a photo request after pricing still sends images',
    $thisTurn !== [],
    'images this turn: ' . count($thisTurn)
);
check(
    'the images are the requested room’s own photos',
    $thisTurn !== [] && str_contains((string) $thisTurn[0], 'rm111-'),
    (string) ($thisTurn[0] ?? 'none')
);
check(
    'no more than three photos go out in one turn',
    count($thisTurn) <= 3,
    'images this turn: ' . count($thisTurn)
);

// And the same request again does not re-send: the loop guard still holds.
$beforeRepeat = count($photoWa->images);
$say('and how big is the room?');
check(
    'a follow-up question does not re-send the photos',
    count($photoWa->images) === $beforeRepeat,
    'extra images: ' . (count($photoWa->images) - $beforeRepeat)
);

$photoLog = Database::run(
    "SELECT * FROM ai_interactions
     WHERE lead_id = (SELECT id FROM leads WHERE wa_phone = ?)
       AND message_kind = 'photos' ORDER BY id DESC LIMIT 1",
    [$photoPhone]
)->fetch();
check('the send is logged as a photos message', $photoLog !== false);
check(
    'the log says why the photos went out',
    $photoLog !== false && str_contains((string) $photoLog['reasoning'], 'asked to see the room'),
    (string) ($photoLog['reasoning'] ?? '')
);

// ---- 2. a promise in the text is always kept --------------------------------
check(
    'promise detector reads the reported message',
    preg_match(SkillSupport::PHOTO_PROMISE, 'Awesome, Hui Wen — sending you the photos of RM-111 in Kota Warisan now 📸') === 1
);
check(
    'promise detector reads "here you go" with photos',
    preg_match(SkillSupport::PHOTO_PROMISE, 'Here you go — photos of the room 📸') === 1
);
check(
    'promise detector ignores an offer of photos',
    preg_match(SkillSupport::PHOTO_PROMISE, 'Want to see photos of the room?') === 0
);

$promisedWa = new class extends \App\Integrations\WhatsApp\WhatsAppClient {
    public array $images = [];

    public function sendText(string $toWaPhone, string $text): array
    {
        return ['message_id' => 'test.text', 'dry_run' => true];
    }

    public function sendImage(string $toWaPhone, string $imageUrl, string $caption = ''): array
    {
        $this->images[] = $imageUrl;

        return ['message_id' => 'test.img', 'dry_run' => true];
    }
};

// A decision with BOTH photo flags off, but a reply text that promises photos:
// the message must not go out alone.
App\AI\Skills\AutomateSkill::run(
    Database::run('SELECT * FROM leads WHERE wa_phone = ?', [$photoPhone])->fetch(),
    [
        'send_photos_first'    => false,
        'send_photos'          => false,
        'recommended_room_ids' => [$photoRoomId],
        'rooms'                => [Room::find($photoRoomId)],
        'next_action'          => 'answer_directly',
        'qualified'            => true,
        'closing_probability'  => 60,
        'lead_signals'         => [],
        'recommendation'       => '',
        'reasoning'            => 'test',
        'model'                => 'test-model',
        'memory_ids'           => [],
    ],
    'Sending you the photos now 📸',
    $promisedWa
);

check(
    'a reply that promises photos never goes out without them',
    $promisedWa->images !== [],
    'images sent: ' . count($promisedWa->images)
);

// ---- 3. unreadable model output degrades honestly ---------------------------
$truncated = '{"qualified": true, "closing_probability": 72, "next_action": "answer_directly",'
    . ' "send_photos": true, "reasoning": "The customer asked to see RM-111 and the tenure that fits their';

$repaired = SkillSupport::extractJson($truncated);
check('a truncated decision is repaired, not discarded', is_array($repaired), var_export($repaired, true));
check('the fields that arrived survive the repair', ($repaired['send_photos'] ?? null) === true);
check('the half-written field is dropped', !array_key_exists('reasoning', $repaired ?? []));

check(
    'a fenced object still parses',
    (SkillSupport::extractJson("Here you go:\n```json\n{\"send_photos\": true}\n```") ?? [])['send_photos'] === true
);
check('genuinely unusable output is still null', SkillSupport::extractJson('I cannot help with that.') === null);

$fallbacks = Database::run(
    "SELECT COUNT(*) AS n FROM ai_activity_log WHERE action IN ('decision_fallback', 'model_json_failed')"
)->fetch();
check(
    'no decision in this run was silently defaulted',
    (int) ($fallbacks['n'] ?? 0) === 0,
    'unreadable-model fallbacks logged: ' . ($fallbacks['n'] ?? '?')
);
