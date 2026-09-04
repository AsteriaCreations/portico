<?php

use App\Enums\AddOnKind;
use App\Enums\EntryCoverageSource;
use App\Filament\Admin\Resources\Events\Pages\EditEvent;
use App\Filament\Admin\Resources\Events\RelationManagers\AttendanceRelationManager as EventAttendanceRelationManager;
use App\Filament\Admin\Resources\Members\Pages\EditMember;
use App\Filament\Admin\Resources\Members\RelationManagers\AttendanceRelationManager as MemberAttendanceRelationManager;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Member;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['active' => true]));

    $entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);

    Plan::create([
        'add_on_id' => $entry->id,
        'price' => 60.00,
        'credit' => 25.00,
        'effective_from' => '2026-01-01',
    ]);
});

test('the attendance relation manager renders on the event edit page', function () {
    $event = Event::factory()->create();
    Attendance::factory()->for($event)->create();

    Livewire::test(EventAttendanceRelationManager::class, [
        'ownerRecord' => $event,
        'pageClass' => EditEvent::class,
    ])->assertSuccessful();
});

test('checking a member in from the event page prices the attendance via the pricing service', function () {
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 40, 'pool_fee' => 0]);
    $member = Member::factory()->create();
    $member->subscriptions()->create([
        'add_on_id' => AddOn::entry()->id,
        'covered_month' => '2026-07-01',
        'amount_paid' => 60,
    ]);

    Livewire::test(EventAttendanceRelationManager::class, [
        'ownerRecord' => $event,
        'pageClass' => EditEvent::class,
    ])
        ->callTableAction('create', data: [
            'member_id' => $member->id,
            'checked_in_at' => now(),
        ])
        ->assertHasNoTableActionErrors();

    $attendance = Attendance::where('event_id', $event->id)->where('member_id', $member->id)->firstOrFail();

    expect($attendance->entry_fee)->toEqual(40)
        ->and($attendance->entry_coverage)->toEqual(25)
        ->and($attendance->entry_covered_by)->toBe(EntryCoverageSource::RegularSubscription)
        ->and($attendance->amount_paid)->toEqual(15)
        ->and($attendance->checked_in_by)->toBe(auth()->id());
});

test('a member cannot be checked in twice for the same event', function () {
    $event = Event::factory()->create();
    $member = Member::factory()->create();
    Attendance::factory()->for($event)->for($member)->create();

    Livewire::test(EventAttendanceRelationManager::class, [
        'ownerRecord' => $event,
        'pageClass' => EditEvent::class,
    ])
        ->callTableAction('create', data: [
            'member_id' => $member->id,
            'checked_in_at' => now(),
        ])
        ->assertHasTableActionErrors(['member_id']);

    expect(Attendance::where('event_id', $event->id)->where('member_id', $member->id)->count())->toBe(1);
});

test('the entry_covered_by badge renders a clean "Regular Subscription" label, not the raw enum value', function () {
    $event = Event::factory()->create();
    $member = Member::factory()->create();
    Attendance::factory()->for($event)->for($member)->create([
        'entry_covered_by' => EntryCoverageSource::RegularSubscription,
    ]);

    Livewire::test(EventAttendanceRelationManager::class, [
        'ownerRecord' => $event,
        'pageClass' => EditEvent::class,
    ])
        ->assertSee('Regular Subscription')
        ->assertDontSee('regular_subscription');
});

test('the attendance relation manager renders on the member edit page', function () {
    $member = Member::factory()->create();
    Attendance::factory()->for($member)->create();

    Livewire::test(MemberAttendanceRelationManager::class, [
        'ownerRecord' => $member,
        'pageClass' => EditMember::class,
    ])->assertSuccessful();
});

test('checking in from the member page prices the attendance via the pricing service', function () {
    $member = Member::factory()->create();
    $event = Event::factory()->create(['event_date' => '2026-07-19', 'entry_fee' => 40, 'pool_fee' => 0]);

    Livewire::test(MemberAttendanceRelationManager::class, [
        'ownerRecord' => $member,
        'pageClass' => EditMember::class,
    ])
        ->callTableAction('create', data: [
            'event_id' => $event->id,
            'checked_in_at' => now(),
        ])
        ->assertHasNoTableActionErrors();

    $attendance = Attendance::where('event_id', $event->id)->where('member_id', $member->id)->firstOrFail();

    expect($attendance->entry_fee)->toEqual(40)
        ->and($attendance->amount_paid)->toEqual(40)
        ->and($attendance->checked_in_by)->toBe(auth()->id());
});
