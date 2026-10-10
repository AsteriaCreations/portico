<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\RegisterShifts\Pages\ListRegisterShifts;
use App\Models\Attendance;
use App\Models\MembershipSetting;
use App\Models\Register;
use App\Models\RegisterShift;
use App\Models\User;
use App\Services\RegisterShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function shiftUserWithRole(Role $role): User
{
    return User::factory()->create(['active' => true, 'role' => $role]);
}

test('a door user can view the register shifts list', function () {
    $door = shiftUserWithRole(Role::Door);

    $this->actingAs($door)->get('/admin/register-shifts')->assertSuccessful();
});

test('a showrunner cannot view the register shifts list', function () {
    $showrunner = shiftUserWithRole(Role::Showrunner);

    $this->actingAs($showrunner)->get('/admin/register-shifts')->assertForbidden();
});

test('register shifts can never be updated or deleted, even by an admin or owner', function () {
    $shift = RegisterShift::factory()->create();

    foreach ([Role::Door, Role::Manager, Role::Admin, Role::Owner] as $role) {
        $user = shiftUserWithRole($role);

        expect($user->can('update', $shift))->toBeFalse()
            ->and($user->can('delete', $shift))->toBeFalse()
            ->and($user->can('create', RegisterShift::class))->toBeFalse();
    }
});

test('manager+ can also view the register shifts list', function () {
    $manager = shiftUserWithRole(Role::Manager);

    $this->actingAs($manager)->get('/admin/register-shifts')->assertSuccessful();
});

test('a door user can view a single shift, including its miscellaneous payments ledger', function () {
    $door = shiftUserWithRole(Role::Door);
    $shift = RegisterShift::factory()->create();

    $this->actingAs($door)->get("/admin/register-shifts/{$shift->id}")->assertSuccessful();
});

test('clicking a shift\'s variance peeks at the math behind it', function () {
    $door = shiftUserWithRole(Role::Door);
    $this->actingAs($door);

    $service = app(RegisterShiftService::class);
    $shift = $service->openShift(Register::factory()->create(), $door, 100);
    Attendance::factory()->create(['register_shift_id' => $shift->id, 'payment_method' => 'cash', 'amount_paid' => 20]);
    Attendance::factory()->create(['register_shift_id' => $shift->id, 'payment_method' => 'venmo', 'amount_paid' => 15]);
    $closed = $service->closeShift($shift, $door, 118);

    Livewire::test(ListRegisterShifts::class)
        ->mountTableAction('varianceMath', $closed)
        ->assertMountedActionModalSee(['Expected in the box', MembershipSetting::formatMoney(120), MembershipSetting::formatMoney(-2), 'Venmo']);
});
