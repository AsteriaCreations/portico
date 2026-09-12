<?php

use App\Enums\Role;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

test('an admin can access the upstream updates page when the flag is enabled', function () {
    MembershipSetting::current()->update(['upstream_check_enabled' => true]);
    $admin = User::factory()->create(['active' => true, 'role' => Role::Admin]);

    $this->actingAs($admin)->get('/admin/upstream-updates')->assertSuccessful();
});

test('a manager is forbidden from the upstream updates page even when the flag is enabled', function () {
    MembershipSetting::current()->update(['upstream_check_enabled' => true]);
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);

    $this->actingAs($manager)->get('/admin/upstream-updates')->assertForbidden();
});

test('an admin is forbidden from the upstream updates page when the flag is disabled', function () {
    MembershipSetting::current()->update(['upstream_check_enabled' => false]);
    $admin = User::factory()->create(['active' => true, 'role' => Role::Admin]);

    $this->actingAs($admin)->get('/admin/upstream-updates')->assertForbidden();
});

test('it shows a list of pending commits when the ref resolves with some ahead', function () {
    MembershipSetting::current()->update([
        'upstream_check_enabled' => true,
        'upstream_remote' => 'portico',
        'upstream_branch' => 'main',
    ]);
    $admin = User::factory()->create(['active' => true, 'role' => Role::Admin]);

    $line = "abc123full\x1fabc123\x1fFix the widget rendering bug\x1fJane Doe\x1f2026-09-01T12:00:00-05:00";
    Process::fake(function ($process) use ($line) {
        if ($process->command[1] === 'rev-parse') {
            return Process::result(exitCode: 0);
        }
        if ($process->command[1] === 'log') {
            return Process::result(output: $line."\n");
        }

        return Process::result();
    });

    $this->actingAs($admin)
        ->get('/admin/upstream-updates')
        ->assertSuccessful()
        ->assertSee('Fix the widget rendering bug')
        ->assertSee('abc123');
});

test('it shows an up-to-date message when the ref resolves with nothing pending', function () {
    MembershipSetting::current()->update([
        'upstream_check_enabled' => true,
        'upstream_remote' => 'portico',
        'upstream_branch' => 'main',
    ]);
    $admin = User::factory()->create(['active' => true, 'role' => Role::Admin]);

    Process::fake(['*' => Process::result(exitCode: 0, output: '')]);

    $this->actingAs($admin)
        ->get('/admin/upstream-updates')
        ->assertSuccessful()
        ->assertSee('Up to date');
});
