<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\UpstreamUpdates;
use App\Models\CommandRun;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

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

test('an admin can trigger the deploy once fully configured, recording a CommandRun success', function () {
    MembershipSetting::current()->update([
        'upstream_check_enabled' => true,
        'deploy_trigger_enabled' => true,
        'deploy_task_name' => 'Portico - Deploy Update',
    ]);
    $admin = User::factory()->create(['active' => true, 'role' => Role::Admin]);
    Process::fake(['*' => Process::result(exitCode: 0)]);

    Livewire::actingAs($admin)
        ->test(UpstreamUpdates::class)
        ->assertActionVisible('triggerDeploy')
        ->callAction('triggerDeploy')
        ->assertHasNoActionErrors();

    Process::assertRan(fn ($process) => $process->command === ['schtasks', '/run', '/TN', 'Portico - Deploy Update']);

    $run = CommandRun::firstWhere('command', 'deploy:trigger');
    expect($run)->not->toBeNull()
        ->and($run->last_success_at)->not->toBeNull();
});

test('a failed schtasks call records a CommandRun failure and never runs the deploy task twice', function () {
    MembershipSetting::current()->update([
        'upstream_check_enabled' => true,
        'deploy_trigger_enabled' => true,
        'deploy_task_name' => 'Portico - Deploy Update',
    ]);
    $admin = User::factory()->create(['active' => true, 'role' => Role::Admin]);
    Process::fake(['*' => Process::result(errorOutput: 'ERROR: The specified task name does not exist.', exitCode: 1)]);

    Livewire::actingAs($admin)
        ->test(UpstreamUpdates::class)
        ->callAction('triggerDeploy');

    $run = CommandRun::firstWhere('command', 'deploy:trigger');
    expect($run)->not->toBeNull()
        ->and($run->last_failure_at)->not->toBeNull()
        ->and($run->last_failure_message)->toContain('does not exist');
});

test('the trigger action is hidden when deploy_trigger_enabled is off', function () {
    MembershipSetting::current()->update([
        'upstream_check_enabled' => true,
        'deploy_trigger_enabled' => false,
        'deploy_task_name' => 'Portico - Deploy Update',
    ]);
    $admin = User::factory()->create(['active' => true, 'role' => Role::Admin]);

    Livewire::actingAs($admin)
        ->test(UpstreamUpdates::class)
        ->assertActionHidden('triggerDeploy');
});

test('the trigger action is hidden when no deploy task name is configured', function () {
    MembershipSetting::current()->update([
        'upstream_check_enabled' => true,
        'deploy_trigger_enabled' => true,
        'deploy_task_name' => null,
    ]);
    $admin = User::factory()->create(['active' => true, 'role' => Role::Admin]);

    Livewire::actingAs($admin)
        ->test(UpstreamUpdates::class)
        ->assertActionHidden('triggerDeploy');
});

test('the trigger-deploy gate denies a manager regardless of configuration', function () {
    // Same "can't force-call a hidden Livewire action" convention as
    // ManagerPerkActionTest's grant-manager-subscription-perk coverage --
    // UpstreamUpdates::canAccess() already blocks a Manager from the page
    // entirely, so the gate itself is what actually proves the floor.
    MembershipSetting::current()->update([
        'upstream_check_enabled' => true,
        'deploy_trigger_enabled' => true,
        'deploy_task_name' => 'Portico - Deploy Update',
    ]);
    $manager = User::factory()->create(['role' => Role::Manager]);

    expect(Gate::forUser($manager)->allows('trigger-deploy'))->toBeFalse();
});
