<?php

use App\Console\Commands\ResetOperationalData;
use App\Enums\Role;
use App\Filament\Admin\Pages\Technical;
use App\Models\CommandRun;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

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

test('the backup filename prefix is configurable', function () {
    config(['backup.prefix' => 'acme']);
    Process::fake(['*' => Process::result(output: '-- fake sql dump contents')]);

    $this->artisan('backup:database')->assertSuccessful();

    $expectedPath = $this->backupDir.'/daily/acme-'.now()->toDateString().'.sql.gz';
    expect(File::exists($expectedPath))->toBeTrue();
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

test('a backup folder that cannot be created fails with the folder and account named, instead of crashing', function () {
    // A file where the folder should be: mkdir() fails, as it does when the
    // web server's account can't write into another account's OneDrive.
    File::put($this->backupDir, 'not a folder');
    Process::fake(['*' => Process::result(output: '-- fake sql dump contents')]);

    $this->artisan('backup:database')
        ->expectsOutputToContain("Can't create the backup folder")
        ->assertFailed();

    $run = CommandRun::firstWhere('command', 'backup:database');
    expect($run->last_failure_message)->toContain("Can't create the backup folder")
        ->and($run->last_failure_message)->toContain('daily');

    File::delete($this->backupDir);
});

test('the technical page reports an unwritable backup folder as a failed backup, not a page error', function () {
    File::put($this->backupDir, 'not a folder');
    Process::fake(['*' => Process::result(output: '-- fake sql dump contents')]);
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Owner]));

    Livewire::test(Technical::class)
        ->callAction('runBackupDatabase')
        ->assertNotified('Backup failed');

    File::delete($this->backupDir);
});

test('the reset button stops cleanly when the backup folder cannot be created, deleting nothing', function () {
    File::put($this->backupDir, 'not a folder');
    Process::fake(['*' => Process::result(output: '-- fake sql dump contents')]);
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Owner]));
    $member = Member::factory()->create();

    Livewire::test(Technical::class)
        ->callAction('resetOperationalData', data: ['confirmation' => ResetOperationalData::CONFIRMATION_PHRASE])
        ->assertNotified('Reset failed — nothing was deleted');

    expect(Member::whereKey($member->id)->exists())->toBeTrue();

    File::delete($this->backupDir);
});
