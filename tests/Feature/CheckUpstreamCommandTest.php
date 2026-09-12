<?php

use App\Models\CommandRun;
use App\Models\MembershipSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

test('a disabled flag no-ops and records a CommandRun success', function () {
    MembershipSetting::current()->update(['upstream_check_enabled' => false]);

    $this->artisan('upstream:check')->assertSuccessful();

    $run = CommandRun::firstWhere('command', 'upstream:check');
    expect($run)->not->toBeNull()
        ->and($run->last_success_at)->not->toBeNull();
});

test('an enabled flag with no remote configured no-ops and records a CommandRun success', function () {
    MembershipSetting::current()->update(['upstream_check_enabled' => true, 'upstream_remote' => null]);

    $this->artisan('upstream:check')->assertSuccessful();

    $run = CommandRun::firstWhere('command', 'upstream:check');
    expect($run)->not->toBeNull()
        ->and($run->last_success_at)->not->toBeNull();
});

test('a failed fetch records a CommandRun failure', function () {
    MembershipSetting::current()->update(['upstream_check_enabled' => true, 'upstream_remote' => 'portico']);
    Process::fake(['*' => Process::result(errorOutput: 'could not resolve host', exitCode: 1)]);

    $this->artisan('upstream:check')->assertFailed();

    $run = CommandRun::firstWhere('command', 'upstream:check');
    expect($run)->not->toBeNull()
        ->and($run->last_failure_at)->not->toBeNull()
        ->and($run->last_failure_message)->toContain('could not resolve host');
});

test('a successful fetch records a CommandRun success', function () {
    MembershipSetting::current()->update([
        'upstream_check_enabled' => true,
        'upstream_remote' => 'portico',
        'upstream_branch' => 'main',
    ]);
    Process::fake(['*' => Process::result(exitCode: 0)]);

    $this->artisan('upstream:check')->assertSuccessful();

    $run = CommandRun::firstWhere('command', 'upstream:check');
    expect($run)->not->toBeNull()
        ->and($run->last_success_at)->not->toBeNull();
});
