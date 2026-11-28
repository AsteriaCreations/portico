<?php

use App\Enums\AddOnKind;
use App\Enums\EntryCoverageSource;
use App\Enums\Role;
use App\Filament\Admin\Resources\Events\Pages\EditEvent;
use App\Filament\Admin\Resources\Events\RelationManagers\CompListRelationManager;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\CompReason;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\User;
use App\Services\CompListImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($this->manager);

    AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    AddOn::create(['name' => AddOn::POOL_NAME, 'subscribable' => true, 'priced_per_event' => true]);

    // Pinned to today: AdmissionPolicy reads the event date, and the
    // factory's random past date can make an adult read as under age.
    $this->event = Event::factory()->create(['event_date' => today()->toDateString(), 'entry_fee' => 40, 'pool_fee' => 5]);
    $this->presenter = CompReason::factory()->create(['name' => 'Presenter', 'active' => true]);
});

function buildCompListUploadFixture(array $rows): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();

    $sheet->fromArray(['member_number_or_username', 'comp_reason', 'notes'], null, 'A1');

    foreach ($rows as $i => $row) {
        $sheet->fromArray($row, null, 'A'.($i + 2));
    }

    $path = tempnam(sys_get_temp_dir(), 'comp-list-upload-').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

function callCompListAction(Event $event, string $action, array $rows)
{
    $path = buildCompListUploadFixture($rows);

    try {
        return Livewire::test(CompListRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => EditEvent::class,
        ])->callTableAction($action, data: ['file' => UploadedFile::fake()->createWithContent(basename($path), file_get_contents($path))]);
    } finally {
        @unlink($path);
    }
}

test('bulk upload comps each row like the form: entry waived, pool still owed, reason and notes kept', function () {
    $alice = Member::factory()->create(['username' => 'alice', 'dob' => '1990-01-01']);
    $bob = Member::factory()->create(['dob' => '1990-01-01']);

    callCompListAction($this->event, 'bulkUploadCompList', [
        ['alice', 'presenter', 'Teaching the 9pm class'],
        [(string) $bob->member_number, 'Presenter', ''],
    ])->assertHasNoTableActionErrors();

    $visit = Attendance::where('event_id', $this->event->id)->where('member_id', $alice->id)->sole();
    expect($visit->checked_in_at)->toBeNull()
        ->and($visit->comp_reason_id)->toBe($this->presenter->id)
        ->and($visit->entry_covered_by)->toBe(EntryCoverageSource::EventComp)
        ->and($visit->entry_coverage)->toEqual(40)
        ->and($visit->amount_paid)->toEqual(5)
        ->and($visit->notes)->toBe('Teaching the 9pm class')
        ->and($visit->checked_in_by)->toBe($this->manager->id)
        ->and($visit->addOns()->count())->toBe(1);

    expect(Attendance::where('event_id', $this->event->id)->where('member_id', $bob->id)->sole()->notes)->toBeNull();
});

test('bad rows are skipped and logged, never guessed', function () {
    Member::factory()->create(['username' => 'alice', 'dob' => '1990-01-01']);
    $carol = Member::factory()->create(['username' => 'carol', 'dob' => '1990-01-01']);
    Attendance::factory()->for($this->event)->create(['member_id' => $carol->id, 'checked_in_at' => null]);
    Member::factory()->create(['username' => 'banned', 'dob' => '1990-01-01', 'is_banned' => true]);
    Member::factory()->create(['username' => 'minor', 'dob' => today()->subYears(16)->toDateString()]);
    Member::factory()->create(['username' => 'retired', 'dob' => '1990-01-01']);
    CompReason::factory()->create(['name' => 'Old reason', 'active' => false]);

    $path = buildCompListUploadFixture([
        ['alice', 'Presenter', ''],
        ['alice', 'Presenter', ''],
        ['nobody', 'Presenter', ''],
        ['carol', 'Presenter', ''],
        ['banned', 'Presenter', ''],
        ['minor', 'Presenter', ''],
        ['retired', 'Old reason', ''],
        ['retired', '', ''],
        ['', 'Presenter', ''],
        ['', '', ''],
    ]);

    try {
        $rows = app(CompListImporter::class)->check($this->event, $path);
        $result = app(CompListImporter::class)->import($this->event, $path, $this->manager);
    } finally {
        @unlink($path);
    }

    expect(collect($rows)->map(fn (array $row) => [$row['row'], $row['member'], $row['problem']])->all())->sequence(
        fn ($row) => $row->toBe([2, 'alice', null]),
        fn ($row) => $row->toBe([3, 'alice', 'alice is already in this file at row 2']),
        fn ($row) => $row->toBe([4, null, "no member found for identifier 'nobody'"]),
        fn ($row) => $row->toBe([5, 'carol', "carol is already on this event's list"]),
        fn ($row) => $row->{1}->toBe('banned')->and($row->value[2])->toStartWith("banned can't be admitted"),
        fn ($row) => $row->{1}->toBe('minor')->and($row->value[2])->toStartWith("minor can't be admitted"),
        fn ($row) => $row->toBe([8, 'retired', "no active comp reason named 'Old reason'"]),
        // Row 8 was rejected, so it doesn't count as "already in this file".
        fn ($row) => $row->toBe([9, 'retired', 'blank comp reason']),
        fn ($row) => $row->toBe([10, null, 'blank member identifier']),
    );

    expect($result['created'])->toBe(1)
        ->and($result['log'])->toHaveCount(8)
        ->and(Attendance::where('event_id', $this->event->id)->count())->toBe(2); // carol + alice
});

test('re-uploading the same file never adds anyone twice', function () {
    Member::factory()->create(['username' => 'alice', 'dob' => '1990-01-01']);

    callCompListAction($this->event, 'bulkUploadCompList', [['alice', 'Presenter', '']]);
    callCompListAction($this->event, 'bulkUploadCompList', [['alice', 'Presenter', '']]);

    expect(Attendance::where('event_id', $this->event->id)->count())->toBe(1);
});

test('rows past the building capacity are skipped, in the check and the upload alike', function () {
    MembershipSetting::current()->update(['venue_capacity' => 2]);
    Attendance::factory()->for($this->event)->create(['checked_in_at' => now()]);
    Member::factory()->create(['username' => 'alice', 'dob' => '1990-01-01']);
    Member::factory()->create(['username' => 'bob', 'dob' => '1990-01-01']);

    $path = buildCompListUploadFixture([['alice', 'Presenter', ''], ['bob', 'Presenter', '']]);

    try {
        $rows = app(CompListImporter::class)->check($this->event, $path);
        $result = app(CompListImporter::class)->import($this->event, $path, $this->manager);
    } finally {
        @unlink($path);
    }

    expect(array_column($rows, 'problem'))->toBe([null, 'building at capacity'])
        ->and($result['created'])->toBe(1)
        ->and(Attendance::where('event_id', $this->event->id)->count())->toBe(2);
});

test('an archived event takes no upload', function () {
    $this->event->forceFill(['archived_at' => now()])->save();
    Member::factory()->create(['username' => 'alice', 'dob' => '1990-01-01']);

    $path = buildCompListUploadFixture([['alice', 'Presenter', '']]);

    try {
        $result = app(CompListImporter::class)->import($this->event, $path, $this->manager);
    } finally {
        @unlink($path);
    }

    expect($result['created'])->toBe(0)
        ->and(Attendance::count())->toBe(0);
});

test('the template downloads with the columns the importer reads', function () {
    Livewire::test(CompListRelationManager::class, [
        'ownerRecord' => $this->event,
        'pageClass' => EditEvent::class,
    ])
        ->callTableAction('downloadCompListTemplate')
        ->assertFileDownloaded('comp-list-upload-template-'.now()->toDateString().'.csv');
});

test('checking a file adds nobody and downloads a results file', function () {
    Member::factory()->create(['username' => 'alice', 'dob' => '1990-01-01']);

    callCompListAction($this->event, 'checkCompListFile', [['alice', 'Presenter', '']])
        ->assertHasNoTableActionErrors()
        ->assertNotified()
        ->assertFileDownloaded('comp-list-upload-check-'.now()->toDateString().'.csv');

    expect(Attendance::count())->toBe(0);
});

test('the template, check and upload are Manager+, like adding one comp by hand', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($door);

    // Door can't open the Comp List tab at all, and each action re-checks
    // the same gate inside its closure.
    expect(CompListRelationManager::canViewForRecord($this->event, EditEvent::class))->toBeFalse()
        ->and(Gate::forUser($door)->allows('create', Attendance::class))->toBeFalse()
        ->and(Gate::forUser($this->manager)->allows('create', Attendance::class))->toBeTrue();
});
