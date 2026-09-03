<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Events\Pages\CreateEvent;
use App\Filament\Admin\Resources\Events\Pages\EditEvent;
use App\Filament\Admin\Resources\Events\Pages\ListEvents;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['active' => true]));
});

test('events list page renders', function () {
    Event::factory()->count(3)->create();

    Livewire::test(ListEvents::class)->assertSuccessful();
});

test('an event can be created', function () {
    $eventType = EventType::factory()->create();

    Livewire::test(CreateEvent::class)
        ->fillForm([
            'event_date' => '2026-08-01',
            'starts_at' => '2026-08-01 20:00:00',
            'ends_at' => '2026-08-01 23:00:00',
            'name' => 'Summer Social',
            'event_type_id' => $eventType->id,
            'entry_fee' => 20,
            'pool_fee' => 5,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $event = Event::where('name', 'Summer Social')->firstOrFail();
    expect($event->entry_fee)->toEqual(20)
        ->and($event->pool_fee)->toEqual(5)
        ->and($event->created_by)->toBe(auth()->id());
});

test('ends_at is required to create an event, and is never auto-filled from event_date', function () {
    $eventType = EventType::factory()->create();

    Livewire::test(CreateEvent::class)
        ->fillForm([
            'event_date' => '2026-08-01',
            'name' => 'Summer Social',
            'event_type_id' => $eventType->id,
            'entry_fee' => 20,
            'pool_fee' => 5,
        ])
        ->call('create')
        ->assertHasFormErrors(['ends_at']);
});

test('starts_at is still required if explicitly cleared after event_date defaults it', function () {
    $eventType = EventType::factory()->create();

    Livewire::test(CreateEvent::class)
        ->fillForm([
            'event_date' => '2026-08-01',
            'starts_at' => null,
            'ends_at' => '2026-08-01 23:00:00',
            'name' => 'Summer Social',
            'event_type_id' => $eventType->id,
            'entry_fee' => 20,
            'pool_fee' => 5,
        ])
        ->call('create')
        ->assertHasFormErrors(['starts_at']);
});

test('ends_at must be after starts_at', function () {
    $eventType = EventType::factory()->create();

    Livewire::test(CreateEvent::class)
        ->fillForm([
            'event_date' => '2026-08-01',
            'starts_at' => '2026-08-01 20:00:00',
            'ends_at' => '2026-08-01 19:00:00',
            'name' => 'Summer Social',
            'event_type_id' => $eventType->id,
            'entry_fee' => 20,
            'pool_fee' => 5,
        ])
        ->call('create')
        ->assertHasFormErrors(['ends_at']);
});

test('comp_list_due_at is optional and can be set on an event', function () {
    $eventType = EventType::factory()->create();

    Livewire::test(CreateEvent::class)
        ->fillForm([
            'event_date' => '2026-08-01',
            'starts_at' => '2026-08-01 20:00:00',
            'ends_at' => '2026-08-01 23:00:00',
            'comp_list_due_at' => '2026-07-25',
            'name' => 'Summer Social',
            'event_type_id' => $eventType->id,
            'entry_fee' => 20,
            'pool_fee' => 5,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $event = Event::where('name', 'Summer Social')->firstOrFail();
    expect($event->comp_list_due_at->toDateString())->toBe('2026-07-25');
});

test('isCompListOverdue is false with no due date, false on or before the due date, and true after it', function () {
    expect((new Event(['comp_list_due_at' => null]))->isCompListOverdue())->toBeFalse();

    $dueToday = Event::factory()->create(['comp_list_due_at' => today()]);
    expect($dueToday->isCompListOverdue())->toBeFalse();

    $dueTomorrow = Event::factory()->create(['comp_list_due_at' => today()->addDay()]);
    expect($dueTomorrow->isCompListOverdue())->toBeFalse();

    $dueYesterday = Event::factory()->create(['comp_list_due_at' => today()->subDay()]);
    expect($dueYesterday->isCompListOverdue())->toBeTrue();
});

test('hasEnded is false with no ends_at, false before it, and true after it', function () {
    expect((new Event(['ends_at' => null]))->hasEnded())->toBeFalse();

    $future = Event::factory()->create(['ends_at' => now()->addHour()]);
    expect($future->hasEnded())->toBeFalse();

    $past = Event::factory()->create(['ends_at' => now()->subHour()]);
    expect($past->hasEnded())->toBeTrue();
});

test('the edit page shows a live Summary section only once the event has ended, with the right numbers', function () {
    $notEnded = Event::factory()->create(['ends_at' => now()->addHour()]);

    Livewire::test(EditEvent::class, ['record' => $notEnded->getRouteKey()])
        ->assertDontSee('Checked in');

    $ended = Event::factory()->create(['ends_at' => now()->subHour()]);
    Attendance::factory()->for($ended)->create(['checked_in_at' => now()->subHours(2), 'amount_paid' => 20]);
    Attendance::factory()->for($ended)->create(['checked_in_at' => null, 'amount_paid' => 15]);

    Livewire::test(EditEvent::class, ['record' => $ended->getRouteKey()])
        ->assertSee('Checked in')
        ->assertSee('$35.00');
});

test('duplicating an already-ended event does not show its Summary section in the modal', function () {
    $original = Event::factory()->create(['ends_at' => now()->subHour()]);
    Attendance::factory()->for($original)->create(['checked_in_at' => now()->subHours(2)]);

    $html = Livewire::test(ListEvents::class)
        ->mountTableAction('duplicate', $original)
        ->html();

    expect($html)->not->toContain('Checked in');
});

test('duplicating an event does not copy the comp list due date', function () {
    $original = Event::factory()->create(['comp_list_due_at' => today()]);

    Livewire::test(ListEvents::class)
        ->callTableAction('duplicate', $original, data: [
            'event_date' => '2026-09-01',
            'starts_at' => '2026-09-01 20:00:00',
            'ends_at' => '2026-09-01 23:00:00',
        ])
        ->assertHasNoTableActionErrors();

    $duplicate = Event::where('id', '!=', $original->id)->latest('id')->firstOrFail();
    expect($duplicate->comp_list_due_at)->toBeNull();
});

test('an event can be edited', function () {
    $event = Event::factory()->create(['name' => 'Original']);

    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->fillForm(['name' => 'Renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($event->refresh()->name)->toBe('Renamed');
});

test('the pool_fee field is hidden on the event form once pool_enabled is off, and visible again once restored', function () {
    MembershipSetting::current()->update(['pool_enabled' => false]);
    $event = Event::factory()->create();

    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->assertSchemaComponentExists('pool_fee', checkComponentUsing: fn ($component) => ! $component->isVisible());

    MembershipSetting::current()->update(['pool_enabled' => true]);

    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->assertSchemaComponentExists('pool_fee', checkComponentUsing: fn ($component) => $component->isVisible());
});

test('the pool_fee column is hidden on the events table once pool_enabled is off', function () {
    MembershipSetting::current()->update(['pool_enabled' => false]);

    Livewire::test(ListEvents::class)
        ->assertTableColumnHidden('pool_fee');

    MembershipSetting::current()->update(['pool_enabled' => true]);

    Livewire::test(ListEvents::class)
        ->assertTableColumnVisible('pool_fee');
});

test('duplicating an event zeroes pool_fee on the new event once pool_enabled is off, even though the source had a fee', function () {
    MembershipSetting::current()->update(['pool_enabled' => false]);
    $original = Event::factory()->create(['pool_fee' => 25]);

    Livewire::test(ListEvents::class)
        ->callTableAction('duplicate', $original, data: [
            'event_date' => '2026-09-01',
            'starts_at' => '2026-09-01 20:00:00',
            'ends_at' => '2026-09-01 23:00:00',
        ])
        ->assertHasNoTableActionErrors();

    $duplicate = Event::where('id', '!=', $original->id)->latest('id')->firstOrFail();
    expect($duplicate->pool_fee)->toEqual(0);
});

test('an admin can designate a member as the event showrunner', function () {
    $event = Event::factory()->create();
    $member = Member::factory()->create();

    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->fillForm(['showrunner_id' => $member->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($event->refresh()->showrunner_id)->toBe($member->id);
});

test('an admin can designate a member as the event host', function () {
    $event = Event::factory()->create();
    $member = Member::factory()->create();

    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->fillForm(['host_id' => $member->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($event->refresh()->host_id)->toBe($member->id);
});

test("starts_at's date defaults from event_date but ends_at is left independent", function () {
    $eventType = EventType::factory()->create();

    Livewire::test(CreateEvent::class)
        ->fillForm(['event_date' => '2026-08-01'])
        ->assertFormSet(['starts_at' => '2026-08-01 00:00:00'])
        ->fillForm([
            'starts_at' => '2026-08-01 22:00:00',
            'ends_at' => '2026-08-02 02:00:00',
            'name' => 'Late Night',
            'event_type_id' => $eventType->id,
            'entry_fee' => 20,
            'pool_fee' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $event = Event::where('name', 'Late Night')->firstOrFail();
    expect($event->starts_at->toDateTimeString())->toBe('2026-08-01 22:00:00')
        ->and($event->ends_at->toDateTimeString())->toBe('2026-08-02 02:00:00');
});

test('an admin can duplicate an event, copying fees/type/flags but requiring a fresh date', function () {
    $eventType = EventType::factory()->create();
    $showrunner = Member::factory()->create();
    $host = Member::factory()->create();
    $original = Event::factory()->create([
        'name' => 'Weekly Social',
        'event_type_id' => $eventType->id,
        'entry_fee' => 20,
        'pool_fee' => 5,
        'door_prepay_enabled' => true,
        'showrunner_id' => $showrunner->id,
        'host_id' => $host->id,
        'notes' => 'Recurring event',
    ]);

    Livewire::test(ListEvents::class)
        ->callTableAction('duplicate', $original, data: [
            'event_date' => '2026-09-01',
            'starts_at' => '2026-09-01 20:00:00',
            'ends_at' => '2026-09-01 23:00:00',
        ])
        ->assertHasNoTableActionErrors();

    $duplicate = Event::where('id', '!=', $original->id)->where('name', 'Weekly Social')->firstOrFail();
    expect($duplicate->event_type_id)->toBe($eventType->id)
        ->and($duplicate->entry_fee)->toEqual(20)
        ->and($duplicate->pool_fee)->toEqual(5)
        ->and($duplicate->door_prepay_enabled)->toBeTrue()
        ->and($duplicate->showrunner_id)->toBe($showrunner->id)
        ->and($duplicate->host_id)->toBe($host->id)
        ->and($duplicate->notes)->toBe('Recurring event')
        ->and($duplicate->event_date->toDateString())->toBe('2026-09-01')
        ->and($duplicate->created_by)->toBe(auth()->id());
});

test('a manager cannot see the duplicate, download template, or bulk upload actions', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));
    $event = Event::factory()->create();

    Livewire::test(ListEvents::class)
        ->assertTableActionHidden('duplicate', $event)
        ->assertActionHidden('downloadEventTemplate')
        ->assertActionHidden('bulkUploadEvents');
});
