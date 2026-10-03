<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\Technical;
use App\Models\CommandRun;
use App\Models\Member;
use App\Models\User;
use App\Services\OperationalDataReset;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->backupDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'portico-reset-test-'.uniqid();
    config([
        'backup.destination' => $this->backupDir,
        'backup.mysqldump_path' => 'mysqldump',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->backupDir);
});

/**
 * Row counts of every kept table, to prove a reset leaves them alone.
 *
 * @return array<string, int>
 */
function keptTableCounts(): array
{
    return collect(OperationalDataReset::KEPT_TABLES)
        ->reject(fn (string $table): bool => in_array($table, ['sessions', 'cache', 'command_runs'], true))
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
        ->all();
}

test('every table is classified as either cleared or kept by a reset', function () {
    $classified = [...OperationalDataReset::CLEARED_TABLES, ...OperationalDataReset::KEPT_TABLES];

    $unclassified = array_values(array_diff(Schema::getTableListing(schemaQualified: false), $classified));

    expect($unclassified)->toBe([])
        ->and(array_intersect(OperationalDataReset::CLEARED_TABLES, OperationalDataReset::KEPT_TABLES))->toBe([]);
});

test('a reset empties members, events and their history but keeps user accounts and configuration', function () {
    $this->seed();
    $this->seed(DemoDataSeeder::class);

    $linkedUser = User::factory()->create(['member_id' => Member::first()->id]);
    $passwordHashes = User::pluck('password', 'id')->all();
    $keptBefore = keptTableCounts();

    expect(DB::table('attendance')->count())->toBeGreaterThan(0);

    app(OperationalDataReset::class)->reset();

    foreach (OperationalDataReset::CLEARED_TABLES as $table) {
        expect(DB::table($table)->count())->toBe(0, "{$table} was not cleared");
    }

    expect(keptTableCounts())->toBe($keptBefore)
        ->and(User::pluck('password', 'id')->all())->toBe($passwordHashes)
        ->and($linkedUser->fresh()->member_id)->toBeNull();
});

test('the command refuses to run without the typed confirmation', function () {
    $member = Member::factory()->create();
    Process::fake();

    $this->artisan('data:reset')
        ->expectsQuestion('Type RESET to continue', 'yes')
        ->assertFailed();

    expect($member->fresh())->not->toBeNull();
    Process::assertNothingRan();
});

test('the command backs up first and deletes nothing when the backup fails', function () {
    $member = Member::factory()->create();
    Process::fake(['*' => Process::result(errorOutput: 'access denied', exitCode: 1)]);

    $this->artisan('data:reset', ['--force' => true])->assertFailed();

    expect($member->fresh())->not->toBeNull()
        ->and(CommandRun::firstWhere('command', 'data:reset')?->last_failure_at)->not->toBeNull();
});

test('the command wipes the data once confirmed and the backup succeeds', function () {
    Member::factory()->count(3)->create();
    Process::fake(['*' => Process::result(output: '-- fake sql dump contents')]);

    $this->artisan('data:reset')
        ->expectsQuestion('Type RESET to continue', 'RESET')
        ->assertSuccessful();

    expect(Member::count())->toBe(0)
        ->and(File::exists($this->backupDir.'/daily/portico-'.now()->toDateString().'.sql.gz'))->toBeTrue()
        ->and(CommandRun::firstWhere('command', 'data:reset')?->last_success_at)->not->toBeNull();
});

test('only an Owner holds the reset gate and sees the Technical page action', function () {
    $admin = User::factory()->create(['active' => true, 'role' => Role::Admin]);
    $owner = User::factory()->create(['active' => true, 'role' => Role::Owner]);

    expect(Gate::forUser($admin)->allows('reset-operational-data'))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('reset-operational-data'))->toBeTrue();

    $this->actingAs($admin);
    Livewire::test(Technical::class)->assertActionHidden('resetOperationalData');

    $this->actingAs($owner);
    Livewire::test(Technical::class)->assertActionVisible('resetOperationalData');
});

test('an Owner can reset from the Technical page only after typing the phrase', function () {
    $owner = User::factory()->create(['active' => true, 'role' => Role::Owner]);
    $this->actingAs($owner);
    Member::factory()->count(2)->create();
    Process::fake(['*' => Process::result(output: '-- fake sql dump contents')]);

    Livewire::test(Technical::class)
        ->callAction('resetOperationalData', data: ['confirmation' => 'reset'])
        ->assertHasActionErrors(['confirmation']);

    expect(Member::count())->toBe(2);

    Livewire::test(Technical::class)
        ->callAction('resetOperationalData', data: ['confirmation' => 'RESET'])
        ->assertHasNoActionErrors();

    expect(Member::count())->toBe(0)
        ->and($owner->fresh())->not->toBeNull();
});
