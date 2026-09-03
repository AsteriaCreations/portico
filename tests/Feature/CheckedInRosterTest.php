<?php

use App\Models\Attendance;
use App\Models\Event;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the roster shows the selected event\'s name and date on every row', function () {
    $event = Event::factory()->create(['name' => 'Friday Social', 'event_date' => '2026-07-19']);
    $member = Member::factory()->create();
    Attendance::factory()->create([
        'member_id' => $member->id,
        'event_id' => $event->id,
        'checked_in_at' => now(),
    ]);

    Livewire::test('checked-in-roster', ['eventId' => $event->id])
        ->assertSee('Friday Social')
        ->assertSee($event->event_date->toFormattedDateString());
});

test('the roster does not show attendance from a different event', function () {
    $event = Event::factory()->create(['name' => 'Friday Social']);
    $otherEvent = Event::factory()->create(['name' => 'Saturday Class']);
    $member = Member::factory()->create();
    Attendance::factory()->create([
        'member_id' => $member->id,
        'event_id' => $otherEvent->id,
        'checked_in_at' => now(),
    ]);

    Livewire::test('checked-in-roster', ['eventId' => $event->id])
        ->assertDontSee('Saturday Class')
        ->assertSee('No one checked in yet.');
});
