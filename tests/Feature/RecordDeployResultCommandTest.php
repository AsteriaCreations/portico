<?php

use App\Models\CommandRun;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a bare call records a CommandRun success', function () {
    $this->artisan('deploy:record-result')->assertSuccessful();

    $run = CommandRun::firstWhere('command', 'deploy');
    expect($run)->not->toBeNull()
        ->and($run->last_success_at)->not->toBeNull();
});

test('--failed with a message records a CommandRun failure', function () {
    $this->artisan('deploy:record-result', ['--failed' => true, '--message' => 'npm run build failed (exit 1).'])
        ->assertFailed();

    $run = CommandRun::firstWhere('command', 'deploy');
    expect($run)->not->toBeNull()
        ->and($run->last_failure_at)->not->toBeNull()
        ->and($run->last_failure_message)->toBe('npm run build failed (exit 1).');
});
