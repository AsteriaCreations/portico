<?php

use App\Enums\AddOnKind;
use App\Enums\EventRepeat;
use App\Enums\Role;
use App\Exceptions\CheckInRefused;
use App\Filament\Admin\Pages\CheckIn;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\Plan;
use App\Models\User;
use App\Services\CheckInRequest;
use App\Services\CheckInService;
use App\Services\EventDuplicator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->irregular = Category::factory()->create(['name' => 'Irregular', 'is_comped' => false]);
    $this->user = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($this->user);

    $entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    Plan::create(['add_on_id' => $entry->id, 'price' => 60, 'credit' => 25, 'effective_from' => '2026-01-01']);

    $this->travelTo(Carbon::parse('2026-10-17 21:00:00'));
    $this->member = Member::factory()->create([
        'category_id' => $this->irregular->id,
        'dob' => '1990-01-01',
        'is_banned' => false,
        'on_watchlist' => false,
        'missing_paperwork' => false,
    ]);
    // A returning member: a first visit is let through after the close.
    Attendance::factory()->for($this->member)->for(Event::factory()->create(['event_date' => '2026-01-10']))
        ->create(['checked_in_at' => '2026-01-10 21:00:00']);
});

/**
 * Tonight's event, 20:00 to $endsAt, with its close time left to the
 * default unless one is given.
 */
function closingEvent(string $endsAt, ?string $closesAt = null): Event
{
    return Event::factory()->create([
        'event_date' => '2026-10-17',
        'starts_at' => '2026-10-17 20:00:00',
        'ends_at' => $endsAt,
        'checkin_closes_at' => $closesAt,
        'entry_fee' => 0,
    ]);
}

test('an event ending the day it starts closes check-in 30 minutes before its end by default', function () {
    expect(closingEvent('2026-10-17 23:00:00')->checkin_closes_at->toDateTimeString())->toBe('2026-10-17 22:30:00');
});

test('an event running past midnight closes check-in an hour before its end by default', function () {
    expect(closingEvent('2026-10-18 02:00:00')->checkin_closes_at->toDateTimeString())->toBe('2026-10-18 01:00:00');
});

test('the default minutes come from Membership Settings, and never close before the event starts', function () {
    MembershipSetting::current()->update(['checkin_close_minutes_overnight' => 90, 'checkin_close_minutes_same_day' => 45]);

    expect(closingEvent('2026-10-18 02:00:00')->checkin_closes_at->toDateTimeString())->toBe('2026-10-18 00:30:00')
        ->and(closingEvent('2026-10-17 23:00:00')->checkin_closes_at->toDateTimeString())->toBe('2026-10-17 22:15:00')
        ->and(closingEvent('2026-10-17 20:30:00')->checkin_closes_at->toDateTimeString())->toBe('2026-10-17 20:00:00');
});

test('a close time set on the event is kept, and moving the end later does not override it on save', function () {
    $event = closingEvent('2026-10-17 23:00:00', '2026-10-17 21:45:00');
    $event->update(['ends_at' => '2026-10-17 23:30:00']);

    expect($event->refresh()->checkin_closes_at->toDateTimeString())->toBe('2026-10-17 21:45:00');
});

test('the desk offers Check in before the close and hides it after, saying when it closed', function () {
    $event = closingEvent('2026-10-17 23:00:00');

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $this->member->id])
        ->assertActionVisible('checkIn');

    $this->travelTo(Carbon::parse('2026-10-17 22:30:00'));

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $this->member->id])
        ->assertActionHidden('checkIn')
        ->assertSee('Check-in closed at 10:30 PM');
});

test('a check-in reaching the service after the close is refused and writes nothing', function () {
    $event = closingEvent('2026-10-17 23:00:00', '2026-10-17 20:45:00');

    expect(fn () => app(CheckInService::class)->record($this->member, $event, $this->user, new CheckInRequest(checkedInAt: now()), null))
        ->toThrow(CheckInRefused::class);

    expect(Attendance::where('event_id', $event->id)->count())->toBe(0);
});

test('someone already on the Prepay List can still be marked arrived after the close', function () {
    $event = closingEvent('2026-10-17 23:00:00', '2026-10-17 20:45:00');
    $attendance = Attendance::factory()->for($this->member)->for($event)->create(['checked_in_at' => null]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $this->member->id])
        ->assertActionVisible('markArrived')
        ->callAction('markArrived')
        ->assertHasNoActionErrors();

    expect($attendance->refresh()->checked_in_at)->not->toBeNull();
});

test('a prepay sold tonight for a later event is not affected by tonight\'s close', function () {
    MembershipSetting::current()->update(['prepay_enabled' => true]);
    closingEvent('2026-10-17 23:00:00', '2026-10-17 20:45:00');
    $later = Event::factory()->create([
        'event_date' => '2026-10-24',
        'starts_at' => '2026-10-24 20:00:00',
        'ends_at' => '2026-10-24 23:00:00',
        'checkin_closes_at' => null,
        'door_prepay_enabled' => true,
        'entry_fee' => 0,
    ]);

    app(CheckInService::class)->record($this->member, $later, $this->user, new CheckInRequest, null);

    expect(Attendance::where('event_id', $later->id)->count())->toBe(1);
});

test('duplicating an event moves its close time with it', function () {
    $type = EventType::factory()->create();
    $source = closingEvent('2026-10-18 02:00:00');

    app(EventDuplicator::class)->duplicate($source, [
        'event_date' => '2026-10-24',
        'starts_at' => '2026-10-24 20:00:00',
        'ends_at' => '2026-10-25 02:00:00',
        'checkin_closes_at' => '2026-10-25 00:15:00',
        'name' => 'Saturday',
        'event_type_id' => $type->id,
        'entry_fee' => 0,
        'pool_fee' => 0,
    ], EventRepeat::Weekly, 2);

    expect(Event::whereDate('event_date', '2026-10-31')->sole()->checkin_closes_at->toDateTimeString())->toBe('2026-11-01 00:15:00');
});

test('a Prospective finishing paperwork after the close can still be checked in at the desk, with a note saying why', function () {
    $prospective = Category::factory()->create(['name' => 'Prospective', 'is_comped' => false]);
    $newcomer = Member::factory()->create([
        'category_id' => $prospective->id,
        'dob' => '1990-01-01',
        'is_banned' => false,
        'on_watchlist' => false,
        'first_name' => null,
        'last_name' => null,
        'email' => null,
    ]);
    $event = closingEvent('2026-10-17 23:00:00', '2026-10-17 20:45:00');

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $newcomer->id])
        ->callAction('saveAndPromote', data: [
            'preferred_name' => 'Newb',
            'first_name' => 'New',
            'last_name' => 'Member',
            'email' => 'new@example.com',
        ])
        ->assertHasNoActionErrors()
        ->assertActionVisible('checkIn')
        ->assertSee('this is their first visit')
        ->callAction('checkIn', data: ['checked_in_at' => now()])
        ->assertHasNoActionErrors();

    expect(Attendance::where('member_id', $newcomer->id)->where('event_id', $event->id)->exists())->toBeTrue();
});

test('an unarrived prepay doesn\'t make a visit, but an arrived one does', function () {
    $newcomer = Member::factory()->create(['category_id' => $this->irregular->id]);
    $event = closingEvent('2026-10-17 23:00:00', '2026-10-17 20:45:00');
    $later = Event::factory()->create(['event_date' => '2026-10-24']);
    Attendance::factory()->for($newcomer)->for($later)->create(['checked_in_at' => null]);

    expect($newcomer->isFirstVisit())->toBeTrue()
        ->and($event->isCheckInClosedFor($newcomer))->toBeFalse()
        ->and($event->isCheckInClosedFor($this->member))->toBeTrue();
});
