<?php

use App\Models\AddOn;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\Subscription;
use App\Services\EmergencyRosterExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PhpOffice\PhpSpreadsheet\IOFactory;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    $this->backupDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'portico-roster-test-'.uniqid();
    config([
        'backup.destination' => $this->backupDir,
        'backup.prefix' => 'portico',
        'backup.mysqldump_path' => 'mysqldump',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->backupDir);
});

/**
 * The roster's data rows keyed by username, each keyed by its column header.
 *
 * @return array<string, array<string, mixed>>
 */
function emergencyRosterRows(): array
{
    $rows = IOFactory::load(EmergencyRosterExporter::path())->getActiveSheet()->toArray();
    $header = $rows[3];

    return collect(array_slice($rows, 4))
        ->map(fn (array $row): array => array_combine($header, $row))
        ->keyBy('Username')
        ->all();
}

test('roster:export lists every member with the door decision and no sensitive detail', function () {
    $adult = now()->subYears(40)->toDateString();
    Member::factory()->create(['username' => 'clear', 'dob' => $adult]);
    Member::factory()->create(['username' => 'banned', 'dob' => $adult, 'is_banned' => true, 'ban_reason' => 'SECRET-BAN-REASON']);
    Member::factory()->create(['username' => 'expired', 'dob' => $adult, 'is_banned' => true, 'banned_until' => now()->subDay()]);
    Member::factory()->create(['username' => 'watched', 'dob' => $adult, 'on_watchlist' => true, 'watchlist_reason' => 'SECRET-WATCH-REASON']);
    Member::factory()->create(['username' => 'minor', 'dob' => now()->subYears(MembershipSetting::current()->age_of_majority - 1)->toDateString()]);
    Member::factory()->create(['username' => 'paperless', 'dob' => $adult, 'missing_paperwork' => true]);
    $subscriber = Member::factory()->create(['username' => 'subscriber', 'dob' => $adult]);
    Subscription::factory()->create([
        'member_id' => $subscriber->id,
        'add_on_id' => AddOn::entry()->id,
        'covered_month' => today()->startOfMonth(),
    ]);

    $this->artisan('roster:export')->assertSuccessful();

    $rows = emergencyRosterRows();
    $month = 'Subscription for '.today()->format('M Y');

    expect($rows)->toHaveCount(Member::count())
        ->and($rows['clear']['Door status'])->toBe('OK')
        ->and($rows['banned']['Door status'])->toBe('Do not admit')
        ->and($rows['expired']['Door status'])->toBe('OK')
        ->and($rows['watched']['Door status'])->toStartWith('Notify ')
        ->and($rows['minor']['Door status'])->toBe('Do not admit')
        ->and($rows['paperless']['Door status'])->toBe('Paperwork needed')
        ->and($rows['subscriber'][$month])->toBe('Yes')
        ->and($rows['clear'][$month])->toBeNull();

    $everything = json_encode(IOFactory::load(EmergencyRosterExporter::path())->getActiveSheet()->toArray());
    expect($everything)->not->toContain('SECRET-BAN-REASON')
        ->not->toContain('SECRET-WATCH-REASON')
        ->not->toContain($adult);
});

test('the roster flags a member under the alcohol age', function () {
    MembershipSetting::current()->update(['age_of_majority' => 18, 'alcohol_flag_age' => 21]);
    Member::factory()->create(['username' => 'nineteen', 'dob' => now()->subYears(19)->toDateString()]);

    $this->artisan('roster:export')->assertSuccessful();

    $row = emergencyRosterRows()['nineteen'];
    expect($row['Door status'])->toBe('OK')
        ->and($row['Flag'])->toContain('Under 21');
});

test('the nightly backup writes the roster only when it is turned on', function () {
    Process::fake(['*' => Process::result(output: '-- fake sql dump contents')]);
    Member::factory()->create();

    $this->artisan('backup:database')->assertSuccessful();
    expect(File::exists(EmergencyRosterExporter::path()))->toBeFalse();

    config(['backup.emergency_roster' => true]);

    $this->artisan('backup:database')->assertSuccessful();
    expect(File::exists(EmergencyRosterExporter::path()))->toBeTrue()
        ->and(EmergencyRosterExporter::path())->toEndWith('roster'.DIRECTORY_SEPARATOR.'portico-roster.xlsx');
});
