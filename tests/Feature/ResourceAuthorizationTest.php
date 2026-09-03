<?php

use App\Enums\Role;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function userWithRole(Role $role): User
{
    return User::factory()->create(['active' => true, 'role' => $role]);
}

test('door is forbidden from members, subscriptions, plans, and event types', function () {
    $door = userWithRole(Role::Door);

    $this->actingAs($door)->get('/admin/members')->assertForbidden();
    $this->actingAs($door)->get('/admin/subscriptions')->assertForbidden();
    $this->actingAs($door)->get('/admin/plans')->assertForbidden();
    $this->actingAs($door)->get('/admin/event-types')->assertForbidden();
});

test('manager can access members, subscriptions, plans, and event types', function () {
    $manager = userWithRole(Role::Manager);

    $this->actingAs($manager)->get('/admin/members')->assertSuccessful();
    $this->actingAs($manager)->get('/admin/subscriptions')->assertSuccessful();
    $this->actingAs($manager)->get('/admin/plans')->assertSuccessful();
    $this->actingAs($manager)->get('/admin/event-types')->assertSuccessful();
});

test('door is forbidden from the events index', function () {
    $door = userWithRole(Role::Door);

    $this->actingAs($door)->get('/admin/events')->assertForbidden();
});

test('manager can view events but cannot edit or create one', function () {
    $manager = userWithRole(Role::Manager);
    $event = Event::factory()->create();

    $this->actingAs($manager)->get('/admin/events')->assertSuccessful();
    $this->actingAs($manager)->get("/admin/events/{$event->id}/edit")->assertForbidden();
    $this->actingAs($manager)->get('/admin/events/create')->assertForbidden();
});

test('admin can create an event', function () {
    $admin = userWithRole(Role::Admin);

    $this->actingAs($admin)->get('/admin/events/create')->assertSuccessful();
});

test('admin can edit an event', function () {
    $admin = userWithRole(Role::Admin);
    $event = Event::factory()->create();

    $this->actingAs($admin)->get("/admin/events/{$event->id}/edit")->assertSuccessful();
});

test('manager is forbidden from the users resource, admin is not', function () {
    $manager = userWithRole(Role::Manager);
    $admin = userWithRole(Role::Admin);

    $this->actingAs($manager)->get('/admin/users')->assertForbidden();
    $this->actingAs($admin)->get('/admin/users')->assertSuccessful();
});

test('owner has every admin-level access, being a superset of the role hierarchy', function () {
    $owner = userWithRole(Role::Owner);

    $this->actingAs($owner)->get('/admin/users')->assertSuccessful();
    $this->actingAs($owner)->get('/admin/events/create')->assertSuccessful();
    $this->actingAs($owner)->get('/admin/members')->assertSuccessful();
    $this->actingAs($owner)->get('/admin/subscriptions')->assertSuccessful();
});
