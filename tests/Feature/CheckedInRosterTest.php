<?php

use App\Models\Attendance;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

test('the roster renders a phone card layout alongside the wide table', function () {
    $event = Event::factory()->create(['name' => 'Friday Social', 'event_date' => '2026-07-19']);
    $member = Member::factory()->create();
    Attendance::factory()->create([
        'member_id' => $member->id,
        'event_id' => $event->id,
        'checked_in_at' => now(),
    ]);

    // The card block keeps a phone off the sideways scroll the 5-column table
    // forces; the table takes over at width. The switch is the component's own
    // scoped CSS: Tailwind's sm:hidden / sm:block aren't in the panel's
    // compiled CSS, so they rendered both and listed everyone twice.
    expect(Livewire::test('checked-in-roster', ['eventId' => $event->id])->html())
        ->toContain('class="checked-in-roster-cards"')
        ->toContain('class="checked-in-roster-table ')
        ->toContain('.checked-in-roster-cards { display: none; }')
        ->toContain('@media (min-width: 640px)')
        ->not->toContain('sm:hidden');
});

test('the roster table uses tabular digits and end-aligns the Paid column', function () {
    $event = Event::factory()->create(['event_date' => '2026-07-19']);
    Attendance::factory()->create([
        'member_id' => Member::factory()->create()->id,
        'event_id' => $event->id,
        'checked_in_at' => now(),
        'amount_paid' => 20,
    ]);

    expect(Livewire::test('checked-in-roster', ['eventId' => $event->id])->html())
        ->toContain('class="fi-ta-table w-full text-start tabular-nums"')
        ->toContain('class="px-3 py-2 text-end text-xs font-medium text-gray-500 dark:text-gray-400">Paid</th>')
        ->toContain('<td class="px-3 py-2 text-end text-sm">$20.00</td>');
});

test('the roster reads the settings row once per render, not once per money cell', function () {
    $event = Event::factory()->create(['event_date' => '2026-07-19']);
    Attendance::factory()->count(20)->create(['event_id' => $event->id, 'checked_in_at' => now()]);
    MembershipSetting::forgetCurrent();

    $settingsQueries = 0;
    DB::listen(function ($query) use (&$settingsQueries): void {
        if (str_contains($query->sql, 'membership_settings')) {
            $settingsQueries++;
        }
    });

    Livewire::test('checked-in-roster', ['eventId' => $event->id])->assertSee('Checked in tonight (20)');

    expect($settingsQueries)->toBe(1);
});
