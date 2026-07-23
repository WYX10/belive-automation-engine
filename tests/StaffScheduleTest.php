<?php

declare(strict_types=1);

/**
 * Staff scheduling: Eve only offers a viewing slot when a rostered agent can
 * actually take it — on shift, not on leave, not already out on another
 * viewing, under their daily cap, and able to run the requested mode.
 *
 * Runs last (alphabetically) so the roster it creates cannot affect the other
 * booking tests, which rely on the no-roster-configured pass-through.
 */

use App\Core\Database;
use App\Models\Booking;
use App\Models\Staff;
use App\Pipeline\Booking\AvailabilityChecker;
use App\Pipeline\Booking\BookingCreator;
use App\Pipeline\Booking\StaffScheduler;
use App\Pipeline\Booking\ViewingMode;

// A weekday at 15:00 that is always in the future — next Wednesday.
$wed = date('Y-m-d 15:00:00', strtotime('next wednesday'));
$wedWeekday = (int) date('w', strtotime($wed));

// ---- no roster yet: scheduling stays out of the way -------------------------
check('no roster configured → every slot counts as staffed', StaffScheduler::coversSlot($wed));
check('no roster configured → assign() returns null', StaffScheduler::assign($wed) === null);

// ---- roster -----------------------------------------------------------------
$aisyah = Staff::create([
    'name' => 'Aisyah', 'role' => 'Viewing agent',
    'handles_video' => 1, 'handles_in_person' => 1, 'max_daily_viewings' => 2,
]);
$daniel = Staff::create([
    'name' => 'Daniel', 'role' => 'Video host',
    'handles_video' => 1, 'handles_in_person' => 0, 'max_daily_viewings' => 8,
]);

Staff::addShift($aisyah, $wedWeekday, '10:00', '18:00');
Staff::addShift($daniel, $wedWeekday, '14:00', '20:00');

check('roster is now configured', Staff::rosterConfigured());

// ---- shift boundaries -------------------------------------------------------
$onDuty = fn (string $slot, ?string $mode = null) => array_map(
    fn ($s) => $s['name'],
    StaffScheduler::availableAt($slot, $mode)
);

check('11:00 → only the agent whose shift covers it', $onDuty(date('Y-m-d 11:00:00', strtotime($wed))) === ['Aisyah'],
    json_encode($onDuty(date('Y-m-d 11:00:00', strtotime($wed)))));
check('09:00 (before every shift) → nobody', $onDuty(date('Y-m-d 09:00:00', strtotime($wed))) === []);
// 19:30 sits inside Daniel's 14:00–20:00 shift, but the viewing would run past it.
check('a viewing that would overrun the shift end is refused',
    $onDuty(date('Y-m-d 19:30:00', strtotime($wed)), ViewingMode::VIDEO_CALL) === [],
    json_encode($onDuty(date('Y-m-d 19:30:00', strtotime($wed)), ViewingMode::VIDEO_CALL)));
check('the wrong weekday has no cover',
    StaffScheduler::coversSlot(date('Y-m-d 15:00:00', strtotime($wed . ' +1 day'))) === false);

// ---- mode capability --------------------------------------------------------
check('in-person request skips the video-only agent',
    $onDuty($wed, ViewingMode::IN_PERSON) === ['Aisyah'], json_encode($onDuty($wed, ViewingMode::IN_PERSON)));
check('video request offers both agents',
    count($onDuty($wed, ViewingMode::VIDEO_CALL)) === 2, json_encode($onDuty($wed, ViewingMode::VIDEO_CALL)));
check('mode not chosen yet → only agents who could do either',
    $onDuty($wed) === ['Aisyah'], json_encode($onDuty($wed)));

// ---- time off ---------------------------------------------------------------
$off = Staff::addTimeOff($aisyah, date('Y-m-d 12:00:00', strtotime($wed)), date('Y-m-d 17:00:00', strtotime($wed)), 'Dentist');
check('time off removes the agent from that window', $onDuty($wed, ViewingMode::IN_PERSON) === []);
check('time off leaves earlier hours alone',
    $onDuty(date('Y-m-d 11:00:00', strtotime($wed)), ViewingMode::IN_PERSON) === ['Aisyah']);
Staff::removeTimeOff($off);
check('removing time off restores the agent', $onDuty($wed, ViewingMode::IN_PERSON) === ['Aisyah']);

// ---- assignment + double-booking -------------------------------------------
$lead = Database::run('SELECT id FROM leads ORDER BY id LIMIT 1')->fetchColumn();
if ($lead === false) {
    $lead = App\Models\Lead::create(['name' => 'Roster Tester', 'wa_phone' => '60129990900', 'source_channel' => 'whatsapp']);
}
$lead = (int) $lead;

$booking = BookingCreator::create($lead, null, $wed, 'test', '', ViewingMode::IN_PERSON);
check('booking is assigned to the rostered agent', (int) $booking['staff_id'] === $aisyah, json_encode($booking['staff_id']));
check('the assigned agent is now busy at that hour', $onDuty($wed, ViewingMode::IN_PERSON) === []);
check('an hour later they are free again',
    $onDuty(date('Y-m-d 16:00:00', strtotime($wed)), ViewingMode::IN_PERSON) === ['Aisyah']);
check('a second in-person viewing at the same hour is not staffable',
    AvailabilityChecker::isSlotFree(null, $wed, ViewingMode::IN_PERSON) === false);
check('the same hour is still fine for a video call (different agent free)',
    AvailabilityChecker::isSlotFree(null, $wed, ViewingMode::VIDEO_CALL));

// ---- daily cap --------------------------------------------------------------
BookingCreator::create($lead, null, date('Y-m-d 11:00:00', strtotime($wed)), 'test', '', ViewingMode::IN_PERSON);
check('agent at their daily cap drops off the roster for that day',
    $onDuty(date('Y-m-d 16:00:00', strtotime($wed)), ViewingMode::IN_PERSON) === [],
    json_encode($onDuty(date('Y-m-d 16:00:00', strtotime($wed)), ViewingMode::IN_PERSON)));
check('the cap does not spill into the next week',
    $onDuty(date('Y-m-d 16:00:00', strtotime($wed . ' +7 day')), ViewingMode::IN_PERSON) === ['Aisyah']);

// ---- alternative suggestion respects the roster ------------------------------
$alternative = AvailabilityChecker::suggestAlternative(null, $wed, ViewingMode::IN_PERSON);
check('suggested alternative is a slot an agent can actually take',
    AvailabilityChecker::isSlotFree(null, $alternative, ViewingMode::IN_PERSON), $alternative);
check('suggested alternative lands on a rostered weekday',
    (int) date('w', strtotime($alternative)) === $wedWeekday, $alternative);

// ---- confirming re-checks the mode against the held agent --------------------
$pending = BookingCreator::create(
    $lead, null, date('Y-m-d 15:00:00', strtotime($wed . ' +7 day')), 'test', '', null, 'pending'
);
check('a mode-less proposal holds an all-rounder', (int) $pending['staff_id'] === $aisyah);

Booking::update((int) $pending['id'], ['staff_id' => $daniel]); // pretend a video-only agent was held
$confirmed = BookingCreator::confirm((int) $pending['id'], ViewingMode::IN_PERSON, null, 'test');
check('confirming in-person moves off an agent who cannot host it',
    (int) $confirmed['staff_id'] === $aisyah, json_encode($confirmed['staff_id']));

// ---- end to end: Eve refuses an unstaffed hour on WhatsApp -------------------
$waCapture = new class extends \App\Integrations\WhatsApp\WhatsAppClient {
    public array $sent = [];

    public function sendText(string $toWaPhone, string $text): array
    {
        $this->sent[] = $text;

        return ['message_id' => 'staff.' . count($this->sent), 'dry_run' => true];
    }

    public function sendImage(string $toWaPhone, string $imageUrl, string $caption = ''): array
    {
        return ['message_id' => 'staff.img', 'dry_run' => true];
    }
};

$phone = '60129990901';
(new \App\Pipeline\Conversion\ConversationManager($waCapture))->handleInbound([
    'wa_phone'   => $phone,
    'name'       => 'Early Bird',
    'text'       => 'i want to book a viewing this wednesday at 8am',
    'message_id' => 'wamid.staff1',
    'timestamp'  => time(),
]);

$reply = $waCapture->sent !== [] ? end($waCapture->sent) : '';
$earlyLead = Database::run('SELECT id FROM leads WHERE wa_phone = ?', [$phone])->fetch();
$earlyBookings = $earlyLead ? Booking::forLead((int) $earlyLead['id']) : [];

check('8am (before any shift) is refused with a staffing reason',
    str_contains($reply, 'None of our team is free'), $reply);
check('the refusal still offers a concrete alternative',
    (bool) preg_match('/\d{1,2}(:\d{2})?(am|pm)/i', $reply), $reply);
check('no booking is held at an hour nobody can staff', $earlyBookings === [], json_encode($earlyBookings));

// ---- leave the roster empty for any later test file --------------------------
Database::run('DELETE FROM bookings WHERE staff_id IS NOT NULL');
Database::run('DELETE FROM staff');
check('teardown leaves no roster behind', Staff::rosterConfigured() === false);
