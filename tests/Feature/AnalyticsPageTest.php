<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a manager can access the analytics page and sees its widgets', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);

    $this->actingAs($manager)
        ->get('/admin/analytics')
        ->assertSuccessful()
        ->assertSee("Tonight's check-ins")
        ->assertSee('This Week')
        ->assertSee('This Week — By Category')
        ->assertSee('Weekly Attendance')
        ->assertSee('Outstanding voucher liability')
        ->assertSee('Active Regular subscribers')
        ->assertSee('Monthly Revenue')
        ->assertSee('Add-On Revenue This Week')
        ->assertSee('Comp Cost This Week')
        ->assertSee('Total register variance this week')
        ->assertSee('Active Membership — By Category')
        ->assertSee('New Members');
});

test('a door volunteer is forbidden from the analytics page', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);

    $this->actingAs($door)->get('/admin/analytics')->assertForbidden();
});
