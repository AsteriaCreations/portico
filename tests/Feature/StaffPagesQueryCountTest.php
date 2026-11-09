<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\MyTasks;
use App\Filament\Admin\Pages\ShiftsWorked;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\RoleTask;
use App\Models\ShiftRole;
use App\Models\ShiftSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Per-row lookups crept into these pages once already (the voucher button
// recounted the month for every row): the query count must not grow with
// the number of staff or shifts.
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-13 22:00'));
    MembershipSetting::current()->update(['staff_scheduling_enabled' => true, 'staff_monthly_shift_requirement' => 3]);
    $this->desk = ShiftRole::factory()->create(['name' => 'Desk']);
    $this->event = Event::factory()->create(['event_date' => '2026-11-13', 'starts_at' => Carbon::parse('2026-11-13 21:00'), 'ends_at' => Carbon::parse('2026-11-14 03:00')]);
    RoleTask::factory()->count(3)->create(['shift_role_id' => $this->desk->id]);

    $this->addStaff = fn (int $count) => User::factory()->count($count)->create(['role' => Role::Door, 'active' => true])
        ->each(fn (User $user) => $user->shiftRoles()->attach($this->desk));
    $this->addShifts = fn (int $count) => ShiftSlot::factory()->count($count)->create([
        'event_id' => $this->event->id, 'shift_role_id' => $this->desk->id, 'starts_at' => '2026-11-13 21:00', 'ends_at' => '2026-11-14 00:30',
    ]);
    // Rendered once first, so one-off lookups (settings, the panel) don't count.
    $this->queries = function (callable $render): int {
        $render();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $render();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
});

test('Shifts Worked runs the same number of queries for 5 staff as for 25', function () {
    $this->actingAs(User::factory()->create(['role' => Role::Manager, 'active' => true]));
    ($this->addStaff)(5);
    $few = ($this->queries)(fn () => Livewire::test(ShiftsWorked::class));

    ($this->addStaff)(20);
    $many = ($this->queries)(fn () => Livewire::test(ShiftsWorked::class));

    expect($many)->toBe($few);
});

test('My tasks doesn\'t look up your roles once per running shift', function () {
    $staff = ($this->addStaff)(1)->first();
    $this->actingAs($staff);
    ($this->addShifts)(1);
    $few = ($this->queries)(fn () => Livewire::test(MyTasks::class));

    ($this->addShifts)(6);
    $many = ($this->queries)(fn () => Livewire::test(MyTasks::class));

    // Each list still loads its own tasks and ticks; the role check doesn't repeat.
    expect($many - $few)->toBeLessThanOrEqual(6 * 2);
});
