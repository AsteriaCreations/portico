<?php

use App\Enums\Role;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the vouchers resource is forbidden when vouchers_enabled is off, even for an admin', function () {
    MembershipSetting::current()->update(['vouchers_enabled' => false]);
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Admin]));

    $this->get('/admin/vouchers')->assertForbidden();
});

test('the vouchers resource is reachable again once vouchers_enabled is restored', function () {
    MembershipSetting::current()->update(['vouchers_enabled' => false]);
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);
    $this->get('/admin/vouchers')->assertForbidden();

    MembershipSetting::current()->update(['vouchers_enabled' => true]);

    $this->get('/admin/vouchers')->assertSuccessful();
});

test('the add-ons resource is forbidden when add_ons_enabled is off, even for a manager', function () {
    MembershipSetting::current()->update(['add_ons_enabled' => false]);
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    $this->get('/admin/add-ons')->assertForbidden();
});

test('the add-ons resource is reachable again once add_ons_enabled is restored', function () {
    MembershipSetting::current()->update(['add_ons_enabled' => false]);
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);
    $this->get('/admin/add-ons')->assertForbidden();

    MembershipSetting::current()->update(['add_ons_enabled' => true]);

    $this->get('/admin/add-ons')->assertSuccessful();
});

test('the register shifts resource is forbidden when register_shifts_enabled is off, even for an admin', function () {
    MembershipSetting::current()->update(['register_shifts_enabled' => false]);
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Admin]));

    $this->get('/admin/register-shifts')->assertForbidden();
});

test('the register shifts resource is reachable again once register_shifts_enabled is restored', function () {
    MembershipSetting::current()->update(['register_shifts_enabled' => false]);
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($door);
    $this->get('/admin/register-shifts')->assertForbidden();

    MembershipSetting::current()->update(['register_shifts_enabled' => true]);

    $this->get('/admin/register-shifts')->assertSuccessful();
});

test('the registers resource is forbidden when register_shifts_enabled is off, even for an admin', function () {
    MembershipSetting::current()->update(['register_shifts_enabled' => false]);
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Admin]));

    $this->get('/admin/registers')->assertForbidden();
});

test('the registers resource is reachable again once register_shifts_enabled is restored', function () {
    MembershipSetting::current()->update(['register_shifts_enabled' => false]);
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);
    $this->get('/admin/registers')->assertForbidden();

    MembershipSetting::current()->update(['register_shifts_enabled' => true]);

    $this->get('/admin/registers')->assertSuccessful();
});
