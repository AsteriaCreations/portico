<?php

use App\Models\Event;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the admin login page is reachable', function () {
    $this->get('/admin/login')->assertSuccessful();
});

test('an active user can access the admin panel', function () {
    $user = User::factory()->create(['active' => true]);

    $this->actingAs($user)->get('/admin')->assertSuccessful();
});

test('an inactive user is denied access to the admin panel', function () {
    $user = User::factory()->create(['active' => false]);

    $this->actingAs($user)->get('/admin')->assertForbidden();
});

test('the events, subscriptions, plans and event types pages render for an active user', function () {
    $user = User::factory()->create(['active' => true]);

    $this->actingAs($user);

    $this->get('/admin/events')->assertSuccessful();
    $this->get('/admin/subscriptions')->assertSuccessful();
    $this->get('/admin/plans')->assertSuccessful();
    $this->get('/admin/event-types')->assertSuccessful();
});

test('an event edit page with its attendance relation manager renders for an active user', function () {
    $user = User::factory()->create(['active' => true]);
    $event = Event::factory()->create();

    $this->actingAs($user)->get("/admin/events/{$event->id}/edit")->assertSuccessful();
});

test('a member edit page with its attendance relation manager renders for an active user', function () {
    $user = User::factory()->create(['active' => true]);
    $member = Member::factory()->create();

    $this->actingAs($user)->get("/admin/members/{$member->id}/edit")->assertSuccessful();
});

test('the check-in page renders for an active user', function () {
    $user = User::factory()->create(['active' => true]);

    $this->actingAs($user)->get('/admin/check-in')->assertSuccessful();
});
