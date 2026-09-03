<?php

use App\Models\CommandRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->backupDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'portico-backup-test-'.uniqid();
    config([
        'backup.destination' => $this->backupDir,
        'backup.retention_days' => 30,
        'backup.mysqldump_path' => 'mysqldump',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->backupDir);
});

test('it dumps, compresses, and writes a daily backup', function () {
    Process::fake(['*' => Process::result(output: '-- fake sql dump contents')]);

    $this->artisan('backup:database')->assertSuccessful();

    $expectedPath = $this->backupDir.'/daily/portico-'.now()->toDateString().'.sql.gz';
    expect(File::exists($expectedPath))->toBeTrue();
    expect(trim(gzdecode(File::get($expectedPath))))->toBe('-- fake sql dump contents');
});

test('a successful run records a CommandRun success', function () {
    Process::fake(['*' => Process::result(output: '-- fake sql dump contents')]);

    $this->artisan('backup:database')->assertSuccessful();

    $run = CommandRun::firstWhere('command', 'backup:database');
    expect($run)->not->toBeNull()
        ->and($run->last_success_at)->not->toBeNull()
        ->and($run->last_success_at->diffInSeconds(now()))->toBeLessThan(5);
});

test('it also writes a monthly copy on the last day of the month', function () {
    Process::fake(['*' => Process::result(output: '-- fake sql dump contents')]);

    $lastDayOfMonth = now()->endOfMonth();
    $this->travelTo($lastDayOfMonth);

    $this->artisan('backup:database')->assertSuccessful();

    $expectedPath = $this->backupDir.'/monthly/portico-'.$lastDayOfMonth->format('Y-m').'-monthly.sql.gz';
    expect(File::exists($expectedPath))->toBeTrue();
});

test('it does not write a monthly copy on an ordinary day', function () {
    Process::fake(['*' => Process::result(output: '-- fake sql dump contents')]);

    $this->travelTo(now()->startOfMonth());

    $this->artisan('backup:database')->assertSuccessful();

    expect(File::isDirectory($this->backupDir.'/monthly'))->toBeTrue();
    expect(File::files($this->backupDir.'/monthly'))->toBeEmpty();
});

test('it prunes daily backups older than the retention window but keeps recent ones', function () {
    Process::fake(['*' => Process::result(output: '-- fake sql dump contents')]);

    File::ensureDirectoryExists($this->backupDir.'/daily');
    $oldFile = $this->backupDir.'/daily/portico-old.sql.gz';
    $recentFile = $this->backupDir.'/daily/portico-recent.sql.gz';
    File::put($oldFile, 'old');
    File::put($recentFile, 'recent');
    touch($oldFile, now()->subDays(45)->timestamp);
    touch($recentFile, now()->subDays(5)->timestamp);

    $this->artisan('backup:database')->assertSuccessful();

    expect(File::exists($oldFile))->toBeFalse();
    expect(File::exists($recentFile))->toBeTrue();
});

test('it fails cleanly and writes nothing if mysqldump fails', function () {
    Process::fake(['*' => Process::result(output: '', errorOutput: 'access denied', exitCode: 1)]);

    $this->artisan('backup:database')->assertFailed();

    expect(File::files($this->backupDir.'/daily'))->toBeEmpty();

    $run = CommandRun::firstWhere('command', 'backup:database');
    expect($run)->not->toBeNull()
        ->and($run->last_failure_at)->not->toBeNull()
        ->and($run->last_failure_message)->toContain('access denied');
});
