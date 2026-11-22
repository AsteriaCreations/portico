<?php

use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\AttendanceAddOn;
use App\Models\CommandRun;
use App\Models\Event;
use App\Models\MembershipSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    MembershipSetting::current()->update(['auto_depart_at_event_end' => true, 'event_window_buffer_minutes' => 60]);
    $this->travelTo(now()->setTime(12, 0));
});

function endedEvent(int $endedMinutesAgo): Event
{
    return Event::factory()->create([
        'event_date' => today(),
        'starts_at' => now()->subHours(6),
        'ends_at' => now()->subMinutes($endedMinutesAgo),
    ]);
}

test('everyone still on site is departed at the event\'s end time once the buffer has passed', function () {
    $event = endedEvent(90);
    $attendance = Attendance::factory()->for($event)->create(['checked_in_at' => now()->subHours(4)]);

    $this->artisan('events:depart-ended')->assertSuccessful();

    expect($attendance->fresh()->departed_at->equalTo($event->ends_at))->toBeTrue()
        ->and(CommandRun::firstWhere('command', 'events:depart-ended')?->last_success_at)->not->toBeNull();
});

test('nobody is departed while the event is still inside its window buffer', function () {
    $attendance = Attendance::factory()->for(endedEvent(30))->create(['checked_in_at' => now()->subHours(4)]);

    $this->artisan('events:depart-ended')->assertSuccessful();

    expect($attendance->fresh()->departed_at)->toBeNull();
});

test('someone who arrived after the end time is departed at their arrival, never before it', function () {
    $event = endedEvent(90);
    $attendance = Attendance::factory()->for($event)->create(['checked_in_at' => now()->subMinutes(75)]);

    $this->artisan('events:depart-ended')->assertSuccessful();

    expect($attendance->fresh()->departed_at->equalTo($attendance->checked_in_at))->toBeTrue();
});

test('an earlier departure, an unarrived prepay and an overnight stay are left alone', function () {
    $event = endedEvent(90);
    $departed = Attendance::factory()->for($event)->create(['checked_in_at' => now()->subHours(4), 'departed_at' => now()->subHours(3)]);
    $prepay = Attendance::factory()->for($event)->create(['checked_in_at' => null]);
    $overnight = Attendance::factory()->for($event)->create(['checked_in_at' => now()->subHours(4)]);
    $addOn = AddOn::factory()->create(['is_overnight' => true]);
    AttendanceAddOn::create(['attendance_id' => $overnight->id, 'add_on_id' => $addOn->id, 'name' => $addOn->name, 'price' => $addOn->price, 'is_overnight' => true]);

    $this->artisan('events:depart-ended')->assertSuccessful();

    expect($departed->fresh()->departed_at->equalTo(now()->subHours(3)))->toBeTrue()
        ->and($prepay->fresh()->departed_at)->toBeNull()
        ->and($overnight->fresh()->departed_at)->toBeNull();
});

test('nothing is departed while the flag is off', function () {
    MembershipSetting::current()->update(['auto_depart_at_event_end' => false]);
    $attendance = Attendance::factory()->for(endedEvent(90))->create(['checked_in_at' => now()->subHours(4)]);

    $this->artisan('events:depart-ended')->assertSuccessful();

    expect($attendance->fresh()->departed_at)->toBeNull();
});
