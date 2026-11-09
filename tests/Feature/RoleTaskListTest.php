<?php

use App\Enums\Role;
use App\Enums\RoleTaskStatus;
use App\Filament\Admin\Resources\RoleTasks\Pages\CreateRoleTask;
use App\Filament\Admin\Resources\RoleTasks\Pages\ListRoleTasks;
use App\Filament\Admin\Resources\TaskAreas\Pages\CreateTaskArea;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\RoleTask;
use App\Models\ShiftRole;
use App\Models\TaskArea;
use App\Models\User;
use App\Services\ShiftAvailabilityService;
use App\Services\ShiftScheduleService;
use App\Services\ShiftSlotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-11 12:00'));
    MembershipSetting::current()->update(['staff_scheduling_enabled' => true]);

    $this->manager = User::factory()->create(['role' => Role::Manager, 'active' => true]);
    $this->desk = ShiftRole::factory()->create(['name' => 'Desk']);
    $this->setup = ShiftRole::factory()->create(['name' => 'Setup', 'open_to_all' => true]);
    $this->cleaning = ShiftRole::factory()->create(['name' => 'Cleaning', 'scheduled' => false]);
});

test('Manager+ keeps the task lists and areas; Door can\'t, and they hide while scheduling is off', function () {
    $door = User::factory()->create(['role' => Role::Door, 'active' => true]);

    expect($this->manager->can('viewAny', RoleTask::class))->toBeTrue()
        ->and($this->manager->can('viewAny', TaskArea::class))->toBeTrue()
        ->and($door->can('viewAny', RoleTask::class))->toBeFalse()
        ->and($door->can('create', TaskArea::class))->toBeFalse();

    MembershipSetting::current()->update(['staff_scheduling_enabled' => false]);

    expect($this->manager->can('viewAny', RoleTask::class))->toBeFalse()
        ->and($this->manager->can('viewAny', TaskArea::class))->toBeFalse();
});

test('a Manager adds an area and a standard task in it', function () {
    $this->actingAs($this->manager);

    Livewire::test(CreateTaskArea::class)
        ->fillForm(['name' => 'Bathrooms', 'notes' => 'Bleach only'])
        ->call('create')
        ->assertHasNoFormErrors();

    $area = TaskArea::sole();

    Livewire::test(CreateRoleTask::class)
        ->fillForm(['shift_role_id' => $this->cleaning->id, 'task_area_id' => $area->id, 'name' => 'Mop the floors'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(RoleTask::sole())
        ->status->toBe(RoleTaskStatus::Standard)
        ->shift_role_id->toBe($this->cleaning->id)
        ->task_area_id->toBe($area->id);
});

test('Task lists shows standard tasks only, not proposals', function () {
    $this->actingAs($this->manager);
    $standard = RoleTask::factory()->create(['shift_role_id' => $this->desk->id, 'name' => 'Count the float']);
    $proposed = RoleTask::factory()->create(['shift_role_id' => $this->desk->id, 'name' => 'Wipe the counter', 'status' => RoleTaskStatus::Proposed]);

    Livewire::test(ListRoleTasks::class)
        ->assertCanSeeTableRecords([$standard])
        ->assertCanNotSeeTableRecords([$proposed]);
});

test('deleting an area leaves its tasks with no area', function () {
    $area = TaskArea::factory()->create();
    $task = RoleTask::factory()->create(['shift_role_id' => $this->cleaning->id, 'task_area_id' => $area->id]);

    $area->delete();

    expect($task->fresh()->task_area_id)->toBeNull();
});

test('a role that isn\'t scheduled gets no staffing counts, shifts, availability or schedule', function () {
    $cleaner = User::factory()->create(['role' => Role::Door, 'active' => true]);
    $cleaner->shiftRoles()->attach($this->cleaning);
    $desker = User::factory()->create(['role' => Role::Door, 'active' => true]);
    $desker->shiftRoles()->attach($this->desk);

    $availability = app(ShiftAvailabilityService::class);

    expect(app(ShiftSlotService::class)->standardRoles()->pluck('name')->all())->toBe(['Desk'])
        ->and($availability->canGiveAvailability($cleaner))->toBeFalse()
        ->and($availability->canGiveAvailability($desker))->toBeTrue()
        ->and($availability->roster()->pluck('id')->all())->toBe([$desker->id])
        ->and(app(ShiftScheduleService::class)->canViewSchedule($cleaner))->toBeFalse()
        // Anyone-can-work roles (Setup) are offered to people with a scheduled role only.
        ->and($this->setup->eligibleUsers()->pluck('id')->all())->toBe([$desker->id]);

    $event = Event::factory()->create(['event_date' => '2026-11-13', 'starts_at' => Carbon::parse('2026-11-13 21:00'), 'ends_at' => Carbon::parse('2026-11-14 03:00')]);
    app(ShiftSlotService::class)->setStandardShifts($event, ShiftSlotService::SHAPE_SINGLE, null, ['single' => [$this->desk->id => 1, $this->cleaning->id => 2]]);

    expect($event->shiftSlots()->pluck('shift_role_id')->all())->toBe([$this->desk->id]);
});
