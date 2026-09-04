<?php

use App\Enums\AddOnKind;
use App\Enums\EntryCoverageSource;
use App\Enums\Role;
use App\Filament\Admin\Pages\CheckIn;
use App\Filament\Admin\Resources\Events\Pages\EditEvent;
use App\Filament\Admin\Resources\Events\RelationManagers\CompListRelationManager;
use App\Filament\Admin\Resources\Events\RelationManagers\PrepayListRelationManager;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\CompReason;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($this->manager);

    // PricingService::price() always resolves the entry target -- present
    // in a real install via AddOnSeeder, seeded directly here for this
    // test's minimal fixture. Pool too, since one test prices a pool event.
    AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    AddOn::create(['name' => AddOn::POOL_NAME, 'subscribable' => true, 'priced_per_event' => true]);
});

test('adding a member to the comp list waives entry via applyEventComp, leaving pool priced independently', function () {
    $event = Event::factory()->create(['entry_fee' => 40, 'pool_fee' => 5]);
    $member = Member::factory()->create();
    $reason = CompReason::factory()->create(['name' => 'Presenter']);

    Livewire::test(CompListRelationManager::class, [
        'ownerRecord' => $event,
        'pageClass' => EditEvent::class,
    ])
        ->callTableAction('create', data: [
            'member_id' => $member->id,
            'comp_reason_id' => $reason->id,
        ])
        ->assertHasNoTableActionErrors();

    $attendance = Attendance::where('event_id', $event->id)->where('member_id', $member->id)->firstOrFail();
    expect($attendance->checked_in_at)->toBeNull()
        ->and($attendance->entry_covered_by)->toBe(EntryCoverageSource::EventComp)
        ->and($attendance->entry_coverage)->toEqual(40)
        ->and($attendance->comp_reason_id)->toBe($reason->id)
        ->and($attendance->amount_paid)->toEqual(5); // pool fee still owed — entry only
});

test('adding to the comp list is rejected once the building is at capacity', function () {
    MembershipSetting::current()->update(['venue_capacity' => 1]);
    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    Attendance::factory()->for($event)->create(['checked_in_at' => now()]);

    $newMember = Member::factory()->create();
    $reason = CompReason::factory()->create();

    Livewire::test(CompListRelationManager::class, [
        'ownerRecord' => $event,
        'pageClass' => EditEvent::class,
    ])
        ->callTableAction('create', data: [
            'member_id' => $newMember->id,
            'comp_reason_id' => $reason->id,
        ])
        ->assertHasTableActionErrors();

    expect(Attendance::where('event_id', $event->id)->where('member_id', $newMember->id)->exists())->toBeFalse();
});

test('the comp list and prepay list stay disjoint', function () {
    $event = Event::factory()->create(['entry_fee' => 40]);
    $reason = CompReason::factory()->create();
    $compedMember = Member::factory()->create();
    $prepaidMember = Member::factory()->create();

    $compedAttendance = Attendance::factory()->for($event)->for($compedMember)->create([
        'checked_in_at' => null,
        'comp_reason_id' => $reason->id,
    ]);
    $prepaidAttendance = Attendance::factory()->for($event)->for($prepaidMember)->create([
        'checked_in_at' => null,
        'comp_reason_id' => null,
    ]);

    Livewire::test(CompListRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->assertCanSeeTableRecords([$compedAttendance])
        ->assertCanNotSeeTableRecords([$prepaidAttendance]);

    Livewire::test(PrepayListRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->assertCanSeeTableRecords([$prepaidAttendance])
        ->assertCanNotSeeTableRecords([$compedAttendance]);
});

test('a comp-listed member shows up in the check-in page back-check-in table', function () {
    $event = Event::factory()->create();
    $reason = CompReason::factory()->create();
    $member = Member::factory()->create();
    $attendance = Attendance::factory()->for($event)->for($member)->create([
        'checked_in_at' => null,
        'comp_reason_id' => $reason->id,
    ]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id])
        ->assertCanSeeTableRecords([$attendance]);
});
