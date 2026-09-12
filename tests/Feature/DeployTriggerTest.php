<?php

use App\Services\DeployTrigger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

test('trigger runs schtasks /run with the exact task name', function () {
    Process::fake(['*' => Process::result(exitCode: 0)]);

    app(DeployTrigger::class)->trigger('Portico - Deploy Update');

    Process::assertRan(fn ($process) => $process->command === ['schtasks', '/run', '/TN', 'Portico - Deploy Update']);
});

test('trigger throws when schtasks fails', function () {
    Process::fake(['*' => Process::result(errorOutput: 'ERROR: The specified task name does not exist.', exitCode: 1)]);

    app(DeployTrigger::class)->trigger('Portico - Deploy Update');
})->throws(RuntimeException::class);

test('a task name starting with a dash is rejected before any process runs', function () {
    Process::fake();

    expect(fn () => app(DeployTrigger::class)->trigger('-TN evil'))
        ->toThrow(InvalidArgumentException::class);

    Process::assertNothingRan();
});

test('a task name starting with a slash is rejected before any process runs', function () {
    Process::fake();

    expect(fn () => app(DeployTrigger::class)->trigger('/TN evil'))
        ->toThrow(InvalidArgumentException::class);

    Process::assertNothingRan();
});

test('a task name is allowed to contain spaces', function () {
    Process::fake(['*' => Process::result(exitCode: 0)]);

    app(DeployTrigger::class)->trigger('IX Membership - Deploy Update');

    Process::assertRan(fn ($process) => $process->command === ['schtasks', '/run', '/TN', 'IX Membership - Deploy Update']);
});
