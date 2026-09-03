<?php

use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\AttendanceAddOn;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('hasRoomOn is always true when max_per_night is unset', function () {
    $addOn = AddOn::factory()->create(['max_per_night' => null]);
    $event = Event::factory()->create(['event_date' => '2026-07-19']);

    $attendance = Attendance::factory()->for($event)->create();
    AttendanceAddOn::factory()->for($attendance)->create(['add_on_id' => $addOn->id]);

    expect($addOn->hasRoomOn(now()->parse('2026-07-19')))->toBeTrue();
});

test('hasRoomOn is false once units sold that night reach max_per_night', function () {
    $addOn = AddOn::factory()->create(['max_per_night' => 1]);
    $event = Event::factory()->create(['event_date' => '2026-07-19']);
    $attendance = Attendance::factory()->for($event)->create();
    AttendanceAddOn::factory()->for($attendance)->create(['add_on_id' => $addOn->id]);

    expect($addOn->hasRoomOn(now()->parse('2026-07-19')))->toBeFalse();
});

test('the cap is shared across every concurrent event that night, not tracked per event', function () {
    $addOn = AddOn::factory()->create(['max_per_night' => 1]);
    $eventA = Event::factory()->create(['event_date' => '2026-07-19']);
    $eventB = Event::factory()->create(['event_date' => '2026-07-19']);

    $attendance = Attendance::factory()->for($eventA)->create();
    AttendanceAddOn::factory()->for($attendance)->create(['add_on_id' => $addOn->id]);

    // The sale happened at eventA, but the room is a shared building-wide
    // resource -- eventB, the same night, must see it as full too.
    expect($addOn->hasRoomOn($eventB->event_date))->toBeFalse();
});

test('the cap resets for a different night', function () {
    $addOn = AddOn::factory()->create(['max_per_night' => 1]);
    $event = Event::factory()->create(['event_date' => '2026-07-19']);
    $attendance = Attendance::factory()->for($event)->create();
    AttendanceAddOn::factory()->for($attendance)->create(['add_on_id' => $addOn->id]);

    expect($addOn->hasRoomOn(now()->parse('2026-07-20')))->toBeTrue();
});

test('a prepay row counts toward the nightly cap immediately, before arrival', function () {
    $addOn = AddOn::factory()->create(['max_per_night' => 1]);
    $event = Event::factory()->create(['event_date' => '2026-07-19']);
    $attendance = Attendance::factory()->for($event)->create(['checked_in_at' => null]);
    AttendanceAddOn::factory()->for($attendance)->create(['add_on_id' => $addOn->id]);

    expect($addOn->hasRoomOn(now()->parse('2026-07-19')))->toBeFalse();
});
