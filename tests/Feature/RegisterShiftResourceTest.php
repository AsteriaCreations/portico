<?php

use App\Enums\Role;
use App\Models\RegisterShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
