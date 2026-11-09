<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\MyTasks;
use App\Filament\Admin\Resources\RoleTasks\RoleTaskResource;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\RoleTask;
use App\Models\RoleTaskCompletion;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\ShiftSlot;
use App\Models\TaskArea;
use App\Models\User;
use App\Services\RoleTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Friday 13 Nov 2026, 22:00, during the Open shift. Schedule week starts Thursday.
    $this->travelTo(Carbon::parse('2026-11-13 22:00'));
    MembershipSetting::current()->update(['staff_scheduling_enabled' => true, 'staff_schedule_week_starts_on' => 4]);

    $this->desk = ShiftRole::factory()->create(['name' => 'Desk']);
    $this->cleaning = ShiftRole::factory()->create(['name' => 'Cleaning Crew', 'scheduled' => false]);

    $this->float = RoleTask::factory()->create(['shift_role_id' => $this->desk->id, 'name' => 'Count the float']);
    $this->bathrooms = TaskArea::factory()->create(['name' => 'Bathrooms', 'notes' => 'Bleach only']);
    $this->mop = RoleTask::factory()->create(['shift_role_id' => $this->cleaning->id, 'task_area_id' => $this->bathrooms->id, 'name' => 'Mop the floors']);

    $person = fn (string $name, array $roles): User => tap(User::factory()->create(['name' => $name, 'role' => Role::Door, 'active' => true]), fn (User $user) => $user->shiftRoles()->attach($roles));
    $this->sam = $person('Sam', [$this->desk->id]);
    $this->jo = $person('Jo', [$this->desk->id, $this->cleaning->id]);
    $this->lee = $person('Lee', [$this->cleaning->id]);

    $event = Event::factory()->create(['name' => 'Friday Social', 'event_date' => '2026-11-13', 'starts_at' => Carbon::parse('2026-11-13 21:00'), 'ends_at' => Carbon::parse('2026-11-14 03:00')]);
    $this->open = ShiftSlot::factory()->create(['event_id' => $event->id, 'shift_role_id' => $this->desk->id, 'kind' => 'open', 'starts_at' => '2026-11-13 21:00', 'ends_at' => '2026-11-14 00:30', 'headcount' => 1]);
    ShiftAssignment::create(['shift_slot_id' => $this->open->id, 'user_id' => $this->sam->id, 'assigned_by' => $this->sam->id]);

    $this->service = app(RoleTaskService::class);
});

test('a running shift shows to the person on it and to others who can work the role', function () {
    expect($this->service->currentShiftsFor($this->sam)->pluck('id')->all())->toBe([$this->open->id])
        ->and($this->service->currentShiftsFor($this->jo)->pluck('id')->all())->toBe([$this->open->id])
        ->and($this->service->currentShiftsFor($this->lee))->toBeEmpty()
        ->and($this->service->weeklyRolesFor($this->lee)->pluck('name')->all())->toBe(['Cleaning Crew']);

    $this->travelTo(Carbon::parse('2026-11-14 01:31'));
    expect($this->service->currentShiftsFor($this->sam))->toBeEmpty();
});

test('ticking on your own shift is on shift; someone else with the role ticking is off shift', function () {
    $this->actingAs($this->sam);
    Livewire::test(MyTasks::class)
        ->assertSee('Count the float')
        ->assertSee('Your shift')
        ->call('tick', $this->float->id, $this->open->id)
        ->assertSee('Done by Sam');

    expect(RoleTaskCompletion::sole())->user_id->toBe($this->sam->id)->on_shift->toBeTrue()->shift_slot_id->toBe($this->open->id);

    RoleTaskCompletion::query()->delete();
    $completion = $this->service->complete($this->jo, $this->float, $this->open);

    expect($completion->on_shift)->toBeFalse();
});

test('a task is done once per shift, and someone without the role can\'t tick it', function () {
    $this->service->complete($this->sam, $this->float, $this->open);

    expect(fn () => $this->service->complete($this->jo, $this->float, $this->open))->toThrow(ValidationException::class, 'Sam already did this one.')
        ->and(fn () => $this->service->complete($this->lee, $this->float, $this->open->fresh()))->toThrow(ValidationException::class);
});

test('a shift that isn\'t running can\'t be ticked', function () {
    $this->travelTo(Carbon::parse('2026-11-14 02:00'));

    expect(fn () => $this->service->complete($this->sam, $this->float, $this->open))->toThrow(ValidationException::class, 'isn\'t running');
});

test('a weekly task is done once per schedule week, by anyone with the role, and reopens the next week', function () {
    $this->actingAs($this->lee);
    Livewire::test(MyTasks::class)
        ->assertSee('Bathrooms')
        ->assertSee('Bleach only')
        ->call('tick', $this->mop->id, null);

    expect(RoleTaskCompletion::sole())->week_start->toDateString()->toBe('2026-11-12')->on_shift->toBeNull();
    expect(fn () => $this->service->complete($this->jo, $this->mop, null))->toThrow(ValidationException::class);
    expect(fn () => $this->service->complete($this->sam, $this->mop, null))->toThrow(ValidationException::class);

    $this->travelTo(Carbon::parse('2026-11-19 10:00'));
    expect($this->service->complete($this->jo, $this->mop, null)->week_start->toDateString())->toBe('2026-11-19');
});

test('only your own tick can be undone, while its shift is still on', function () {
    $completion = $this->service->complete($this->sam, $this->float, $this->open);

    expect(fn () => $this->service->undo($this->jo, $completion))->toThrow(ValidationException::class);

    $this->service->undo($this->sam, $completion);
    expect(RoleTaskCompletion::count())->toBe(0);

    $completion = $this->service->complete($this->sam, $this->float, $this->open);
    $this->travelTo(Carbon::parse('2026-11-14 02:00'));
    expect(fn () => $this->service->undo($this->sam, $completion->fresh()))->toThrow(ValidationException::class);
});

test('History lists your own ticks, with off-shift ones marked', function () {
    $this->service->complete($this->jo, $this->float, $this->open);
    $this->service->complete($this->lee, $this->mop, null);

    $this->actingAs($this->jo);
    Livewire::test(MyTasks::class)
        ->callAction('toggleDisplay')
        ->assertCanSeeTableRecords(RoleTaskCompletion::where('user_id', $this->jo->id)->get())
        ->assertCanNotSeeTableRecords(RoleTaskCompletion::where('user_id', $this->lee->id)->get())
        ->assertSee('Off shift');
});

test('My tasks is for people with a shift role, while scheduling is on; a task with ticks can\'t be deleted', function () {
    $manager = User::factory()->create(['role' => Role::Manager, 'active' => true]);
    $this->actingAs($manager);
    expect(MyTasks::canAccess())->toBeFalse();

    $this->actingAs($this->lee);
    expect(MyTasks::canAccess())->toBeTrue();
    MembershipSetting::current()->update(['staff_scheduling_enabled' => false]);
    expect(MyTasks::canAccess())->toBeFalse();
    MembershipSetting::current()->update(['staff_scheduling_enabled' => true]);

    $this->service->complete($this->lee, $this->mop, null);
    expect($manager->can('delete', $this->mop))->toBeFalse()
        ->and($manager->can('delete', $this->float))->toBeTrue()
        ->and(RoleTaskResource::canAccess())->toBeFalse();
});

test('the Cleaning Checklist moves onto a weekly Cleaning Crew role, with its history and crew', function () {
    $migration = 'database/migrations/2026_11_22_120000_move_cleaning_into_role_tasks.php';
    $this->artisan('migrate:rollback', ['--path' => $migration])->assertSuccessful();
    MembershipSetting::current()->update(['staff_scheduling_enabled' => false]);
    ShiftRole::where('name', 'Cleaning Crew')->update(['name' => 'Old cleaning']);

    $wipeId = DB::table('cleaning_tasks')->insertGetId(['name' => 'Wipe the bar', 'description' => 'Both sides', 'sort_order' => 2, 'active' => true]);
    DB::table('cleaning_tasks')->insert(['name' => 'Old task', 'sort_order' => 1, 'active' => false]);
    DB::table('cleaning_task_completions')->insert(['cleaning_task_id' => $wipeId, 'completed_by' => $this->lee->id, 'for_week_start' => '2026-11-09', 'created_at' => '2026-11-10 12:00:00']);
    DB::table('user_capabilities')->insert(['user_id' => $this->sam->id, 'capability' => 'cleaning_crew', 'granted_by' => $this->sam->id]);

    $this->artisan('migrate', ['--path' => $migration])->assertSuccessful();

    $role = ShiftRole::where('name', 'Cleaning Crew')->sole();
    $wipe = RoleTask::where('name', 'Wipe the bar')->sole();

    expect($role->scheduled)->toBeFalse()
        ->and($role->counts_toward_requirement)->toBeFalse()
        ->and($role->users->pluck('id')->all())->toBe([$this->sam->id])
        ->and($wipe->shift_role_id)->toBe($role->id)
        ->and($wipe->description)->toBe('Both sides')
        ->and(RoleTask::where('name', 'Old task')->sole()->active)->toBeFalse()
        ->and($wipe->completions()->sole())->user_id->toBe($this->lee->id)->week_start->toDateString()->toBe('2026-11-09')
        ->and(MembershipSetting::current()->staff_scheduling_enabled)->toBeTrue()
        ->and(Schema::hasTable('cleaning_tasks'))->toBeFalse()
        ->and(Schema::hasTable('user_capabilities'))->toBeFalse();
});
