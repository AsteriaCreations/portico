<?php

use App\Enums\Role;
use App\Enums\ShiftKind;
use App\Filament\Admin\Pages\Staffing;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\ShiftRole;
use App\Models\ShiftSlot;
use App\Models\User;
use App\Services\ShiftSlotBulkImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-11 12:00'));
    MembershipSetting::current()->update(['staff_scheduling_enabled' => true]);

    $this->manager = User::factory()->create(['role' => Role::Manager, 'active' => true]);
    $this->actingAs($this->manager);

    $this->setup = ShiftRole::factory()->create(['name' => 'Setup']);
    $this->desk = ShiftRole::factory()->create(['name' => 'Desk']);

    $this->friday = Event::factory()->create(['name' => 'Friday Social', 'event_date' => '2026-11-13', 'starts_at' => Carbon::parse('2026-11-13 21:00'), 'ends_at' => Carbon::parse('2026-11-14 03:00')]);
    $this->saturday = Event::factory()->create(['name' => 'Saturday Social', 'event_date' => '2026-11-14', 'starts_at' => Carbon::parse('2026-11-14 21:00'), 'ends_at' => Carbon::parse('2026-11-15 03:00')]);
});

function buildShiftUploadFixture(array $rows): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();

    $sheet->fromArray(['event_date', 'event_name', 'shift_role', 'kind', 'label', 'starts_at', 'ends_at', 'people_needed', 'notes'], null, 'A1');

    foreach ($rows as $i => $row) {
        $sheet->fromArray($row, null, 'A'.($i + 2));
    }

    $path = tempnam(sys_get_temp_dir(), 'shift-upload-').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

function callShiftAction(string $action, array $rows)
{
    $path = buildShiftUploadFixture($rows);

    try {
        return Livewire::test(Staffing::class)
            ->callAction($action, data: ['file' => UploadedFile::fake()->createWithContent(basename($path), file_get_contents($path))]);
    } finally {
        @unlink($path);
    }
}

test('bulk upload adds setup and extra shifts across events', function () {
    callShiftAction('bulkUploadShifts', [
        ['2026-11-13', '', 'setup', 'Setup', 'Bar setup', '2026-11-13 18:00', '2026-11-13 21:00', '3', 'Bring the ice'],
        ['2026-11-14', 'saturday social', 'Desk', '', 'Late cover', '2026-11-15 00:00', '2026-11-15 03:00', '1', ''],
    ])->assertHasNoErrors();

    $setup = ShiftSlot::where('event_id', $this->friday->id)->sole();
    expect($setup->kind)->toBe(ShiftKind::Setup)
        ->and($setup->shift_role_id)->toBe($this->setup->id)
        ->and($setup->label)->toBe('Bar setup')
        ->and($setup->starts_at->toDateTimeString())->toBe('2026-11-13 18:00:00')
        ->and($setup->ends_at->toDateTimeString())->toBe('2026-11-13 21:00:00')
        ->and($setup->headcount)->toBe(3)
        ->and($setup->notes)->toBe('Bring the ice');

    $extra = ShiftSlot::where('event_id', $this->saturday->id)->sole();
    expect($extra->kind)->toBe(ShiftKind::Extra)
        ->and($extra->shift_role_id)->toBe($this->desk->id)
        ->and($extra->notes)->toBeNull();
});

test('date and time cells from a spreadsheet are read as well as text', function () {
    callShiftAction('bulkUploadShifts', [[
        Date::PHPToExcel(Carbon::parse('2026-11-13')),
        '',
        'Setup',
        'setup',
        '',
        Date::PHPToExcel(Carbon::parse('2026-11-13 18:30')),
        Date::PHPToExcel(Carbon::parse('2026-11-13 21:00')),
        2,
        '',
    ]]);

    $slot = ShiftSlot::sole();
    expect($slot->event_id)->toBe($this->friday->id)
        ->and($slot->starts_at->toDateTimeString())->toBe('2026-11-13 18:30:00')
        ->and($slot->headcount)->toBe(2);
});

test('bad rows are skipped and logged, never guessed, and the check agrees', function () {
    Event::factory()->create(['name' => 'Second Friday', 'event_date' => '2026-11-13']);
    ShiftRole::factory()->create(['name' => 'Cleaning', 'scheduled' => false]);
    ShiftSlot::factory()->create(['event_id' => $this->saturday->id, 'shift_role_id' => $this->desk->id, 'kind' => ShiftKind::Extra, 'label' => 'Late cover', 'starts_at' => '2026-11-15 00:00', 'ends_at' => '2026-11-15 03:00', 'headcount' => 1]);

    $ok = ['2026-11-14', '', 'Setup', 'setup', '', '2026-11-14 18:00', '2026-11-14 21:00', '2', ''];

    $path = buildShiftUploadFixture([
        $ok,
        $ok,
        ['2026-11-13', '', 'Setup', 'setup', '', '2026-11-13 18:00', '2026-11-13 21:00', '2', ''],
        ['2026-11-13', 'Nope', 'Setup', 'setup', '', '2026-11-13 18:00', '2026-11-13 21:00', '2', ''],
        ['2026-11-20', '', 'Setup', 'setup', '', '2026-11-20 18:00', '2026-11-20 21:00', '2', ''],
        ['someday', '', 'Setup', 'setup', '', '2026-11-14 18:00', '2026-11-14 21:00', '2', ''],
        ['2026-11-14', '', 'Cleaning', 'extra', '', '2026-11-14 18:00', '2026-11-14 21:00', '2', ''],
        ['2026-11-14', '', 'Desk', 'open', '', '2026-11-14 21:00', '2026-11-15 00:00', '2', ''],
        ['2026-11-14', '', 'Desk', 'extra', '', '2026-11-14 22:00', '2026-11-14 21:00', '2', ''],
        ['2026-11-14', '', 'Desk', 'extra', '', '2026-11-14 22:00', '2026-11-14 23:00', '0', ''],
        ['2026-11-14', '', 'Desk', 'extra', 'late COVER', '2026-11-15 00:00', '2026-11-15 03:00', '1', ''],
        ['', '', '', '', '', '', '', '', ''],
    ]);

    try {
        $rows = app(ShiftSlotBulkImporter::class)->check($path);
        expect(ShiftSlot::count())->toBe(1);

        $result = app(ShiftSlotBulkImporter::class)->import($path);
    } finally {
        @unlink($path);
    }

    expect(collect($rows)->map(fn (array $row) => [$row['row'], $row['problem']])->all())->toBe([
        [2, null],
        [3, 'same shift as row 2'],
        [4, '2 events on 2026-11-13; give the event\'s name'],
        [5, "no event named 'Nope' on 2026-11-13"],
        [6, 'no event on 2026-11-20'],
        [7, "unparseable event date 'someday'"],
        [8, "no scheduled shift role named 'Cleaning'"],
        [9, "kind 'open' must be setup or extra (standard shifts are set on the event's Staffing)"],
        [10, 'ends at is not after starts at'],
        [11, "people needed '0' must be a whole number from 1 to 50"],
        [12, 'this shift is already on the event'],
    ]);

    expect($result['created'])->toBe(1)
        ->and(ShiftSlot::count())->toBe(2);
});

test('an archived event is never matched', function () {
    $this->friday->forceFill(['archived_at' => now()])->save();

    $path = buildShiftUploadFixture([['2026-11-13', '', 'Setup', 'setup', '', '2026-11-13 18:00', '2026-11-13 21:00', '2', '']]);

    try {
        $rows = app(ShiftSlotBulkImporter::class)->check($path);
    } finally {
        @unlink($path);
    }

    expect($rows[0]['problem'])->toBe('no event on 2026-11-13');
});

test('re-uploading the same file never adds anything twice', function () {
    $row = ['2026-11-13', '', 'Setup', 'setup', 'Bar setup', '2026-11-13 18:00', '2026-11-13 21:00', '3', ''];

    callShiftAction('bulkUploadShifts', [$row]);
    callShiftAction('bulkUploadShifts', [$row]);

    expect(ShiftSlot::count())->toBe(1);
});

test('the template downloads, and a check adds nothing and downloads a results file', function () {
    Livewire::test(Staffing::class)
        ->callAction('downloadShiftTemplate')
        ->assertFileDownloaded('shift-upload-template-'.now()->toDateString().'.csv');

    callShiftAction('checkShiftFile', [['2026-11-13', '', 'Setup', 'setup', '', '2026-11-13 18:00', '2026-11-13 21:00', '2', '']])
        ->assertHasNoErrors()
        ->assertNotified()
        ->assertFileDownloaded('shift-upload-check-'.now()->toDateString().'.csv');

    expect(ShiftSlot::count())->toBe(0);
});

test('the upload is Manager+, and gone while staff scheduling is off', function () {
    $door = User::factory()->create(['role' => Role::Door, 'active' => true]);

    expect($door->can('create', ShiftSlot::class))->toBeFalse();

    $this->actingAs($door);
    expect(Staffing::canAccess())->toBeFalse();

    $this->actingAs($this->manager);
    MembershipSetting::current()->update(['staff_scheduling_enabled' => false]);
    expect(Staffing::canAccess())->toBeFalse();
});
