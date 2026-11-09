<?php

use App\Enums\EventRepeat;
use App\Enums\Role;
use App\Enums\ShiftKind;
use App\Filament\Admin\Pages\FeatureFlags;
use App\Filament\Admin\Resources\Events\Pages\EditEvent;
use App\Filament\Admin\Resources\Events\RelationManagers\ShiftSlotsRelationManager;
use App\Filament\Admin\Resources\ShiftRoles\Pages\CreateShiftRole;
use App\Filament\Admin\Resources\ShiftRoles\ShiftRoleResource;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\ShiftSlot;
use App\Models\User;
use App\Services\EventDuplicator;
use App\Services\ShiftAvailabilityService;
use App\Services\ShiftScheduleService;
use App\Services\ShiftSlotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    MembershipSetting::current()->update(['staff_scheduling_enabled' => true, 'staff_single_shift_days' => [0, 1, 2, 3, 4]]);

    $this->manager = User::factory()->create(['role' => Role::Manager, 'active' => true]);
    $this->actingAs($this->manager);

    $this->desk = ShiftRole::factory()->create(['name' => 'Desk', 'sort_order' => 1]);
    $this->dm = ShiftRole::factory()->create(['name' => 'DM', 'sort_order' => 2]);
    $this->setup = ShiftRole::factory()->create(['name' => 'Setup', 'open_to_all' => true, 'sort_order' => 3]);
});

/**
 * Friday 20 Nov 2026, 9pm to 3am.
 */
function staffedEvent(array $overrides = []): Event
{
    return Event::factory()->create([
        'name' => 'Friday Social',
        'event_date' => '2026-11-20',
        'starts_at' => Carbon::parse('2026-11-20 21:00'),
        'ends_at' => Carbon::parse('2026-11-21 03:00'),
        ...$overrides,
    ]);
}

test('Open runs to the handover and Close starts 30 minutes before it, to the end', function () {
    $event = staffedEvent();

    app(ShiftSlotService::class)->setStandardShifts($event, ShiftSlotService::SHAPE_OPEN_CLOSE, Carbon::parse('2026-11-21 00:00'), [
        'open' => [$this->desk->id => 2, $this->dm->id => 1],
        'close' => [$this->desk->id => 1, $this->dm->id => 1],
    ]);

    $open = $event->shiftSlots()->where('kind', 'open')->where('shift_role_id', $this->desk->id)->sole();
    $close = $event->shiftSlots()->where('kind', 'close')->where('shift_role_id', $this->desk->id)->sole();

    expect($event->shiftSlots()->count())->toBe(4)
        ->and([$open->starts_at->format('H:i'), $open->ends_at->format('H:i'), $open->headcount])->toBe(['21:00', '00:00', 2])
        ->and([$close->starts_at->format('H:i'), $close->ends_at->format('H:i'), $close->headcount])->toBe(['23:30', '03:00', 1])
        ->and($open->displayName())->toBe('Desk – Open');
});

test('the handover follows Membership Settings', function () {
    MembershipSetting::current()->update(['staff_handover_minutes' => 60]);
    $event = staffedEvent();

    app(ShiftSlotService::class)->setStandardShifts($event, ShiftSlotService::SHAPE_OPEN_CLOSE, Carbon::parse('2026-11-21 00:00'), ['close' => [$this->dm->id => 1]]);

    expect($event->shiftSlots()->sole()->starts_at->format('H:i'))->toBe('23:00');
});

test('a single-shift night defaults to one shift for the whole event; other nights to Open and Close at halfway', function () {
    $sunday = staffedEvent(['event_date' => '2026-11-22', 'starts_at' => Carbon::parse('2026-11-22 19:00'), 'ends_at' => Carbon::parse('2026-11-22 23:00')]);
    $friday = staffedEvent();
    $service = app(ShiftSlotService::class);

    expect($service->shapeFor($sunday))->toBe(ShiftSlotService::SHAPE_SINGLE)
        ->and($service->shapeFor($friday))->toBe(ShiftSlotService::SHAPE_OPEN_CLOSE)
        ->and($service->openEndsAtFor($friday)->format('Y-m-d H:i'))->toBe('2026-11-21 00:00');

    $service->setStandardShifts($sunday, ShiftSlotService::SHAPE_SINGLE, null, ['single' => [$this->desk->id => 1]]);

    $slot = $sunday->shiftSlots()->sole();
    expect([$slot->kind, $slot->starts_at->format('H:i'), $slot->ends_at->format('H:i'), $slot->displayName()])
        ->toBe([ShiftKind::Single, '19:00', '23:00', 'Desk']);
});

test('changing the numbers keeps whoever is scheduled, and refuses to drop them', function () {
    $event = staffedEvent();
    $service = app(ShiftSlotService::class);
    $service->setStandardShifts($event, ShiftSlotService::SHAPE_OPEN_CLOSE, Carbon::parse('2026-11-21 00:00'), ['open' => [$this->desk->id => 2]]);
    $slot = $event->shiftSlots()->sole();
    $staff = User::factory()->count(2)->create(['active' => true]);
    foreach ($staff as $person) {
        ShiftAssignment::create(['shift_slot_id' => $slot->id, 'user_id' => $person->id, 'assigned_by' => $this->manager->id]);
    }

    // Moving the handover moves the shift; the people stay on it.
    $service->setStandardShifts($event, ShiftSlotService::SHAPE_OPEN_CLOSE, Carbon::parse('2026-11-21 00:30'), ['open' => [$this->desk->id => 2]]);
    expect($slot->fresh()->ends_at->format('H:i'))->toBe('00:30')
        ->and($slot->assignments()->count())->toBe(2);

    expect(fn () => $service->setStandardShifts($event, ShiftSlotService::SHAPE_OPEN_CLOSE, Carbon::parse('2026-11-21 00:30'), ['open' => [$this->desk->id => 1]]))
        ->toThrow(ValidationException::class, '2 people are already scheduled on Desk – Open')
        ->and(fn () => $service->setStandardShifts($event, ShiftSlotService::SHAPE_SINGLE, null, ['single' => [$this->desk->id => 2]]))
        ->toThrow(ValidationException::class, 'Someone is scheduled on Desk – Open');

    expect($slot->fresh()->headcount)->toBe(2);
});

test('Open has to end inside the event, past the handover', function (string $openEndsAt) {
    expect(fn () => app(ShiftSlotService::class)->setStandardShifts(staffedEvent(), ShiftSlotService::SHAPE_OPEN_CLOSE, Carbon::parse($openEndsAt), ['open' => [$this->desk->id => 1]]))
        ->toThrow(ValidationException::class, 'Open has to end before the event does');
})->with(['2026-11-20 21:20', '2026-11-21 03:00']);

test('Managers set standard shifts from the event\'s Staffing tab', function () {
    $event = staffedEvent();

    Livewire::test(ShiftSlotsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->assertTableActionVisible('setStandardShifts')
        ->callTableAction('setStandardShifts', data: [
            'shape' => ShiftSlotService::SHAPE_OPEN_CLOSE,
            'open_ends_at' => '2026-11-21 00:00:00',
            'counts' => ['open' => [$this->desk->id => 2, $this->dm->id => 1], 'close' => [$this->desk->id => 1]],
        ])
        ->assertHasNoTableActionErrors();

    expect($event->shiftSlots()->count())->toBe(3)
        ->and($event->shiftSlots()->where('shift_role_id', $this->setup->id)->exists())->toBeFalse();
});

test('setup and partial-coverage shifts are added with their own times and label', function () {
    $event = staffedEvent();

    Livewire::test(ShiftSlotsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->callTableAction('create', data: ['kind' => 'setup', 'shift_role_id' => $this->setup->id, 'headcount' => 4, 'starts_at' => '2026-11-20 18:00', 'ends_at' => '2026-11-20 21:00'])
        ->assertHasNoTableActionErrors();

    Livewire::test(ShiftSlotsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->callTableAction('create', data: ['kind' => 'extra', 'label' => 'Late cover', 'shift_role_id' => $this->desk->id, 'headcount' => 1, 'starts_at' => '2026-11-20 23:00', 'ends_at' => '2026-11-21 02:00'])
        ->assertHasNoTableActionErrors();

    expect($event->shiftSlots()->orderBy('starts_at')->get()->map->displayName()->all())->toBe(['Setup', 'Desk – Late cover']);
});

test('a role open to everyone is offered to, and can be scheduled with, anyone on the roster', function () {
    $event = staffedEvent(['event_date' => '2026-11-13', 'starts_at' => Carbon::parse('2026-11-13 21:00'), 'ends_at' => Carbon::parse('2026-11-14 02:00')]);
    $setupShift = ShiftSlot::factory()->create(['event_id' => $event->id, 'shift_role_id' => $this->setup->id, 'kind' => 'setup', 'starts_at' => '2026-11-13 18:00', 'ends_at' => '2026-11-13 21:00', 'headcount' => 3]);
    $deskOnly = User::factory()->create(['name' => 'Desk Person', 'active' => true]);
    $this->desk->users()->attach($deskOnly);
    $noRoles = User::factory()->create(['active' => true]);
    $owner = User::factory()->create(['role' => Role::Owner, 'active' => true]);

    $this->travelTo(Carbon::parse('2026-11-11 12:00'));
    MembershipSetting::current()->update(['staff_schedule_week_starts_on' => 4]);

    expect(app(ShiftAvailabilityService::class)->slotsFor($deskOnly, Carbon::parse('2026-11-12'))->modelKeys())->toBe([$setupShift->id])
        ->and(array_keys(app(ShiftScheduleService::class)->candidateOptions(app(ShiftScheduleService::class)->slotsFor(Carbon::parse('2026-11-12'))->first())))->toBe([$deskOnly->id])
        ->and(app(ShiftScheduleService::class)->assign($owner, Carbon::parse('2026-11-12'), [$setupShift->id => [$deskOnly->id]]))->toBe(1)
        ->and(fn () => app(ShiftScheduleService::class)->assign($owner, Carbon::parse('2026-11-12'), [$setupShift->id => [$noRoles->id]]))->toThrow(ValidationException::class);
});

test('a duplicated or repeated event copies the shifts, moved to its date, with nobody on them', function () {
    $event = staffedEvent();
    app(ShiftSlotService::class)->setStandardShifts($event, ShiftSlotService::SHAPE_OPEN_CLOSE, Carbon::parse('2026-11-21 00:00'), ['open' => [$this->desk->id => 2]]);
    ShiftAssignment::create(['shift_slot_id' => $event->shiftSlots()->sole()->id, 'user_id' => $this->manager->id, 'assigned_by' => $this->manager->id]);

    app(EventDuplicator::class)->duplicate($event, [
        'name' => $event->name,
        'event_type_id' => $event->event_type_id,
        'entry_fee' => 20,
        'event_date' => '2026-11-27',
        'starts_at' => '2026-11-27 21:00:00',
        'ends_at' => '2026-11-28 03:00:00',
    ], EventRepeat::Weekly, 2);

    $copy = Event::whereDate('event_date', '2026-12-04')->sole()->shiftSlots()->sole();

    expect([$copy->kind, $copy->starts_at->format('Y-m-d H:i'), $copy->ends_at->format('Y-m-d H:i'), $copy->headcount, $copy->assignments()->count()])
        ->toBe([ShiftKind::Open, '2026-12-04 21:00', '2026-12-05 00:00', 2, 0]);
});

test('a shift role records who can work it', function () {
    $this->actingAs(User::factory()->create(['role' => Role::Admin, 'active' => true]));
    $staff = User::factory()->count(2)->create(['active' => true]);

    Livewire::test(CreateShiftRole::class)
        ->fillForm(['name' => 'Rover', 'users' => $staff->pluck('id')->all(), 'sort_order' => 0, 'active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ShiftRole::where('name', 'Rover')->sole()->users()->pluck('users.id')->sort()->values()->all())
        ->toBe($staff->pluck('id')->sort()->values()->all());
});

test('a shift role with shifts on events can\'t be deleted, one without can', function () {
    $this->actingAs(User::factory()->create(['role' => Role::Admin, 'active' => true]));
    ShiftSlot::factory()->create(['shift_role_id' => $this->desk->id]);

    expect(Gate::allows('delete', $this->desk))->toBeFalse()
        ->and(Gate::allows('delete', $this->dm))->toBeTrue();
});

test('Shift Roles is Admin and up; staffing an event is Manager and up', function (Role $role, bool $roles, bool $staffing) {
    $this->actingAs(User::factory()->create(['role' => $role, 'active' => true]));

    expect(ShiftRoleResource::canAccess())->toBe($roles)
        ->and(Gate::allows('create', ShiftSlot::class))->toBe($staffing);
})->with([
    [Role::Door, false, false],
    [Role::Manager, false, true],
    [Role::Admin, true, true],
]);

test('the Staffing tab and Shift Roles disappear while the flag is off', function () {
    $event = staffedEvent();
    MembershipSetting::current()->update(['staff_scheduling_enabled' => false]);
    $this->actingAs(User::factory()->create(['role' => Role::Owner, 'active' => true]));

    expect(ShiftRoleResource::canAccess())->toBeFalse()
        ->and(ShiftSlotsRelationManager::canViewForRecord($event, EditEvent::class))->toBeFalse();
});

test('the schedule week follows its own setting, or the club week when blank', function () {
    $wednesday = Carbon::parse('2026-11-11 15:00');

    MembershipSetting::current()->update(['week_starts_on' => 1, 'staff_schedule_week_starts_on' => null]);
    expect(MembershipSetting::startOfScheduleWeek($wednesday)->toDateString())->toBe('2026-11-09');

    MembershipSetting::current()->update(['staff_schedule_week_starts_on' => 4]);
    expect(MembershipSetting::startOfScheduleWeek($wednesday)->toDateString())->toBe('2026-11-05');
});

test('turning staff scheduling on reminds about the setup still undone', function () {
    $admin = User::factory()->create(['role' => Role::Admin, 'active' => true]);
    $this->actingAs($admin);
    MembershipSetting::current()->update(['staff_scheduling_enabled' => false]);

    Livewire::test(FeatureFlags::class)
        ->fillForm(['staff_scheduling_enabled' => true])
        ->callAction('save')
        ->assertHasNoActionErrors();

    expect($admin->fresh()->notifications->pluck('data.title')->sort()->values()->all())->toBe([
        'Staff scheduling: add your shift roles',
        'Staff scheduling: choose the first day of the schedule week',
    ]);
});
