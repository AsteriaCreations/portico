<?php

use App\Enums\EventRepeat;
use App\Enums\Role;
use App\Filament\Admin\Pages\FeatureFlags;
use App\Filament\Admin\Resources\Events\Pages\EditEvent;
use App\Filament\Admin\Resources\Events\RelationManagers\ShiftSlotsRelationManager;
use App\Filament\Admin\Resources\EventTypes\Pages\EditEventType;
use App\Filament\Admin\Resources\EventTypes\RelationManagers\ShiftTemplatesRelationManager;
use App\Filament\Admin\Resources\ShiftRoles\Pages\CreateShiftRole;
use App\Filament\Admin\Resources\ShiftRoles\ShiftRoleResource;
use App\Models\Event;
use App\Models\EventType;
use App\Models\MembershipSetting;
use App\Models\ShiftRole;
use App\Models\ShiftSlot;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\EventDuplicator;
use App\Services\ShiftSlotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    MembershipSetting::current()->update(['staff_scheduling_enabled' => true]);

    $this->admin = User::factory()->create(['role' => Role::Admin, 'active' => true]);
    $this->actingAs($this->admin);

    $this->type = EventType::factory()->create();
    $this->door = ShiftRole::factory()->create(['name' => 'Door']);
    $this->bar = ShiftRole::factory()->create(['name' => 'Bar']);

    ShiftTemplate::factory()->create(['event_type_id' => $this->type->id, 'shift_role_id' => $this->door->id, 'start_offset_minutes' => -30, 'duration_minutes' => 270, 'headcount' => 2]);
    ShiftTemplate::factory()->create(['event_type_id' => $this->type->id, 'shift_role_id' => $this->bar->id, 'start_offset_minutes' => 60, 'duration_minutes' => 180]);
});

function scheduledEvent(object $test, array $overrides = []): Event
{
    return Event::factory()->create([
        'event_type_id' => $test->type->id,
        'event_date' => '2026-11-19',
        'starts_at' => Carbon::parse('2026-11-19 21:00'),
        'ends_at' => Carbon::parse('2026-11-20 02:00'),
        ...$overrides,
    ]);
}

test('a new event gets its type\'s shifts, timed from the event\'s start', function () {
    $event = scheduledEvent($this);

    $slots = $event->shiftSlots()->orderBy('starts_at')->get();

    expect($slots)->toHaveCount(2)
        ->and($slots[0]->shift_role_id)->toBe($this->door->id)
        ->and($slots[0]->starts_at->format('Y-m-d H:i'))->toBe('2026-11-19 20:30')
        ->and($slots[0]->ends_at->format('Y-m-d H:i'))->toBe('2026-11-20 01:00')
        ->and($slots[0]->headcount)->toBe(2)
        ->and($slots[1]->starts_at->format('H:i'))->toBe('22:00');
});

test('no shifts are added while staff scheduling is off, for an inactive role, or with no start time', function () {
    MembershipSetting::current()->update(['staff_scheduling_enabled' => false]);
    expect(scheduledEvent($this)->shiftSlots()->count())->toBe(0);

    MembershipSetting::current()->update(['staff_scheduling_enabled' => true]);
    $this->bar->update(['active' => false]);
    expect(scheduledEvent($this)->shiftSlots()->count())->toBe(1)
        ->and(scheduledEvent($this, ['starts_at' => null, 'ends_at' => null])->shiftSlots()->count())->toBe(0);
});

test('filling from the template again only adds what is missing', function () {
    $event = scheduledEvent($this);
    $event->shiftSlots()->where('shift_role_id', $this->bar->id)->delete();

    expect(app(ShiftSlotService::class)->fillFromTemplate($event))->toBe(1)
        ->and(app(ShiftSlotService::class)->fillFromTemplate($event))->toBe(0)
        ->and($event->shiftSlots()->count())->toBe(2);
});

test('repeating an event weekly gives each copy its type\'s shifts', function () {
    $event = scheduledEvent($this);

    app(EventDuplicator::class)->duplicate($event, [
        'name' => $event->name,
        'event_type_id' => $this->type->id,
        'entry_fee' => 20,
        'event_date' => '2026-11-26',
        'starts_at' => '2026-11-26 21:00:00',
        'ends_at' => '2026-11-27 02:00:00',
    ], EventRepeat::Weekly, 2);

    $copies = Event::whereDate('event_date', '>', '2026-11-19')->orderBy('event_date')->get();

    expect($copies)->toHaveCount(2)
        ->and($copies[1]->shiftSlots()->orderBy('starts_at')->first()->starts_at->format('Y-m-d H:i'))->toBe('2026-12-03 20:30');
});

test('Admin adds shifts from the template on the event\'s tab', function () {
    MembershipSetting::current()->update(['staff_scheduling_enabled' => false]);
    $event = scheduledEvent($this);
    MembershipSetting::current()->update(['staff_scheduling_enabled' => true]);

    Livewire::test(ShiftSlotsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->callTableAction('fillFromTemplate');

    expect($event->shiftSlots()->count())->toBe(2);
});

test('Admin adds a one-off shift on an event, which must end after it starts', function () {
    $event = scheduledEvent($this);

    Livewire::test(ShiftSlotsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->callTableAction('create', data: ['shift_role_id' => $this->bar->id, 'headcount' => 1, 'starts_at' => '2026-11-19 23:00', 'ends_at' => '2026-11-19 22:00'])
        ->assertHasTableActionErrors(['ends_at']);

    Livewire::test(ShiftSlotsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->callTableAction('create', data: ['shift_role_id' => $this->bar->id, 'headcount' => 1, 'starts_at' => '2026-11-19 23:00', 'ends_at' => '2026-11-20 01:00', 'notes' => 'Cover'])
        ->assertHasNoTableActionErrors();

    expect($event->shiftSlots()->where('notes', 'Cover')->exists())->toBeTrue();
});

test('Admin sets an event type\'s shifts on its tab', function () {
    $type = EventType::factory()->create();

    Livewire::test(ShiftTemplatesRelationManager::class, ['ownerRecord' => $type, 'pageClass' => EditEventType::class])
        ->callTableAction('create', data: ['shift_role_id' => $this->door->id, 'start_offset_minutes' => -60, 'duration_minutes' => 300, 'headcount' => 3])
        ->assertHasNoTableActionErrors();

    expect($type->shiftTemplates()->sole()->only(['start_offset_minutes', 'duration_minutes', 'headcount']))
        ->toBe(['start_offset_minutes' => -60, 'duration_minutes' => 300, 'headcount' => 3]);
});

test('a shift role records who can work it', function () {
    $staff = User::factory()->count(2)->create(['active' => true]);

    Livewire::test(CreateShiftRole::class)
        ->fillForm(['name' => 'Setup', 'users' => $staff->pluck('id')->all(), 'sort_order' => 0, 'active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ShiftRole::where('name', 'Setup')->sole()->users()->pluck('users.id')->sort()->values()->all())
        ->toBe($staff->pluck('id')->sort()->values()->all())
        ->and($staff->first()->shiftRoles()->pluck('name')->all())->toBe(['Setup']);
});

test('a shift role with shifts on events can\'t be deleted, one without can', function () {
    scheduledEvent($this);
    $unused = ShiftRole::factory()->create();

    expect(Gate::allows('delete', $this->door))->toBeFalse()
        ->and(Gate::allows('delete', $unused))->toBeTrue();
});

test('staff scheduling screens are Admin and up, and only with the flag on', function (Role $role, bool $allowed) {
    $this->actingAs(User::factory()->create(['role' => $role, 'active' => true]));

    expect(ShiftRoleResource::canAccess())->toBe($allowed)
        ->and(Gate::allows('create', ShiftSlot::class))->toBe($allowed)
        ->and(Gate::allows('create', ShiftTemplate::class))->toBe($allowed);
})->with([
    [Role::Manager, false],
    [Role::Admin, true],
    [Role::Owner, true],
]);

test('the screens and tabs disappear while the flag is off', function () {
    $event = scheduledEvent($this);
    MembershipSetting::current()->update(['staff_scheduling_enabled' => false]);

    expect(ShiftRoleResource::canAccess())->toBeFalse()
        ->and(ShiftSlotsRelationManager::canViewForRecord($event, EditEvent::class))->toBeFalse()
        ->and(ShiftTemplatesRelationManager::canViewForRecord($this->type, EditEventType::class))->toBeFalse();
});

test('the schedule week follows its own setting, or the club week when blank', function () {
    $wednesday = Carbon::parse('2026-11-11 15:00');

    MembershipSetting::current()->update(['week_starts_on' => 1, 'staff_schedule_week_starts_on' => null]);
    expect(MembershipSetting::startOfScheduleWeek($wednesday)->toDateString())->toBe('2026-11-09');

    MembershipSetting::current()->update(['staff_schedule_week_starts_on' => 4]);
    expect(MembershipSetting::startOfScheduleWeek($wednesday)->toDateString())->toBe('2026-11-05');
});

test('turning staff scheduling on reminds about the setup still undone', function () {
    MembershipSetting::current()->update(['staff_scheduling_enabled' => false]);

    Livewire::test(FeatureFlags::class)
        ->fillForm(['staff_scheduling_enabled' => true])
        ->callAction('save')
        ->assertHasNoActionErrors();

    // Templates exist (beforeEach), but no role has anyone who can work it
    // and the schedule week isn't set.
    expect($this->admin->fresh()->notifications->pluck('data.title')->sort()->values()->all())->toBe([
        'Staff scheduling: add your shift roles',
        'Staff scheduling: choose the first day of the schedule week',
    ]);
});
