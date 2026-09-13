<?php

use App\Services\UpstreamUpdateChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

test('pendingCommits returns null when the ref cannot be resolved', function () {
    Process::fake(function ($process) {
        if ($process->command[3] === 'rev-parse') {
            return Process::result(exitCode: 1);
        }

        return Process::result();
    });

    expect(app(UpstreamUpdateChecker::class)->pendingCommits('portico', 'main'))->toBeNull();
});

test('pendingCommits returns an empty array when the ref resolves with nothing pending', function () {
    Process::fake(function ($process) {
        if ($process->command[3] === 'rev-parse') {
            return Process::result(exitCode: 0);
        }
        if ($process->command[3] === 'log') {
            return Process::result(output: '');
        }

        return Process::result();
    });

    expect(app(UpstreamUpdateChecker::class)->pendingCommits('portico', 'main'))->toBe([]);
});

test('pendingCommits parses git log output into commit rows', function () {
    $line = "abc123full\x1fabc123\x1fFix the thing\x1fJane Doe\x1f2026-09-01T12:00:00-05:00";

    Process::fake(function ($process) use ($line) {
        if ($process->command[3] === 'rev-parse') {
            return Process::result(exitCode: 0);
        }
        if ($process->command[3] === 'log') {
            return Process::result(output: $line."\n");
        }

        return Process::result();
    });

    $commits = app(UpstreamUpdateChecker::class)->pendingCommits('portico', 'main');

    expect($commits)->toHaveCount(1)
        ->and($commits[0]['hash'])->toBe('abc123full')
        ->and($commits[0]['short_hash'])->toBe('abc123')
        ->and($commits[0]['subject'])->toBe('Fix the thing')
        ->and($commits[0]['author'])->toBe('Jane Doe')
        ->and($commits[0]['date']->toDateString())->toBe('2026-09-01');
});

test('fetch throws when git fetch fails', function () {
    Process::fake(['*' => Process::result(errorOutput: 'could not resolve host', exitCode: 1)]);

    app(UpstreamUpdateChecker::class)->fetch('portico');
})->throws(RuntimeException::class);

test('an unsafe remote name is rejected before any process runs', function () {
    Process::fake();

    expect(fn () => app(UpstreamUpdateChecker::class)->fetch('-o'))
        ->toThrow(InvalidArgumentException::class);

    Process::assertNothingRan();
});

test('an unsafe branch name is rejected before any process runs', function () {
    Process::fake();

    expect(fn () => app(UpstreamUpdateChecker::class)->pendingCommits('portico', '--upload-pack=evil'))
        ->toThrow(InvalidArgumentException::class);

    Process::assertNothingRan();
});

test('every git call scopes a safe.directory exception to this checkout', function () {
    // A web-request-triggered call can run as a different OS account than
    // whoever owns the checkout (e.g. a service account vs. Apache running
    // as LocalSystem), which trips git's "dubious ownership" protection
    // unless this exact directory is allow-listed for that invocation.
    Process::fake(['*' => Process::result(exitCode: 0)]);

    app(UpstreamUpdateChecker::class)->fetch('portico');

    Process::assertRan(fn ($process) => $process->command === ['git', '-c', 'safe.directory='.base_path(), 'fetch', 'portico']);
});
