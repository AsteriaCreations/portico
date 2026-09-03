<?php

use App\Models\Attendance;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\OccupancyAdjustment;
use App\Services\CapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new CapacityService;
});

test('occupancy sums attendance across all of a given date\'s concurrent events, not just one', function () {
    $eventA = Event::factory()->create(['event_date' => '2026-07-19']);
    $eventB = Event::factory()->create(['event_date' => '2026-07-19']);
    $otherDay = Event::factory()->create(['event_date' => '2026-07-20']);

    Attendance::factory()->for($eventA)->count(2)->create();
    Attendance::factory()->for($eventB)->count(3)->create();
    Attendance::factory()->for($otherDay)->count(5)->create();

    expect($this->service->occupancy(now()->parse('2026-07-19')))->toBe(5);
});

test('a prepay counts toward occupancy immediately, before arrival', function () {
    $event = Event::factory()->create(['event_date' => '2026-07-19']);
    Attendance::factory()->for($event)->create(['checked_in_at' => null]);

    expect($this->service->occupancy(now()->parse('2026-07-19')))->toBe(1);
});

test('a departure adjustment reduces effective occupancy', function () {
    $event = Event::factory()->create(['event_date' => '2026-07-19']);
    Attendance::factory()->for($event)->count(3)->create();

    OccupancyAdjustment::factory()->create(['for_date' => '2026-07-19', 'delta' => -2]);

    expect($this->service->occupancy(now()->parse('2026-07-19')))->toBe(1);
});

test('a departed attendance row no longer counts toward occupancy', function () {
    $event = Event::factory()->create(['event_date' => '2026-07-19']);
    Attendance::factory()->for($event)->count(2)->create();
    Attendance::factory()->for($event)->create(['departed_at' => now()]);

    expect($this->service->occupancy(now()->parse('2026-07-19')))->toBe(2);
});

test('occupancy never goes below zero even if adjustments overcorrect', function () {
    $event = Event::factory()->create(['event_date' => '2026-07-19']);
    Attendance::factory()->for($event)->create();

    OccupancyAdjustment::factory()->create(['for_date' => '2026-07-19', 'delta' => -50]);

    expect($this->service->occupancy(now()->parse('2026-07-19')))->toBe(0);
});

test('hasRoom is always true when venue_capacity is unset', function () {
    MembershipSetting::current()->update(['venue_capacity' => null]);
    $event = Event::factory()->create(['event_date' => '2026-07-19']);
    Attendance::factory()->for($event)->count(50)->create();

    expect($this->service->hasRoom(now()->parse('2026-07-19')))->toBeTrue();
});

test('hasRoom is false once occupancy reaches capacity', function () {
    MembershipSetting::current()->update(['venue_capacity' => 2]);
    $event = Event::factory()->create(['event_date' => '2026-07-19']);
    Attendance::factory()->for($event)->count(2)->create();

    expect($this->service->hasRoom(now()->parse('2026-07-19')))->toBeFalse();
});
