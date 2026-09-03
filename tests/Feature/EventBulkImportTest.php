<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Events\Pages\ListEvents;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['active' => true, 'role' => Role::Admin]);
    $this->actingAs($this->admin);
});

function buildEventUploadFixture(array $rows): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();

    $sheet->fromArray([
        'event_date', 'starts_at', 'ends_at', 'name', 'event_type',
        'entry_fee', 'pool_fee', 'door_prepay_enabled',
        'showrunner_username', 'host_username', 'notes',
    ], null, 'A1');

    foreach ($rows as $i => $row) {
        $sheet->fromArray($row, null, 'A'.($i + 2));
    }

    $path = tempnam(sys_get_temp_dir(), 'event-upload-').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

function callEventBulkUpload(string $path)
{
    return Livewire::test(ListEvents::class)
        ->callAction('bulkUploadEvents', data: ['file' => UploadedFile::fake()->createWithContent(basename($path), file_get_contents($path))]);
}

test('bulk upload creates valid rows, resolving event type and showrunner/host by name', function () {
    $eventType = EventType::factory()->create(['name' => 'Social']);
    $showrunner = Member::factory()->create(['username' => 'runner1']);
    $host = Member::factory()->create(['username' => 'host1']);

    $path = buildEventUploadFixture([
        ['2026-09-01', '2026-09-01 20:00', '2026-09-01 23:00', 'Fall Social', 'Social', 20, 5, 'Y', 'runner1', 'host1', 'note here'],
    ]);

    try {
        callEventBulkUpload($path)->assertHasNoErrors();
    } finally {
        @unlink($path);
    }

    $event = Event::where('name', 'Fall Social')->firstOrFail();
    expect($event->event_type_id)->toBe($eventType->id)
        ->and($event->entry_fee)->toEqual(20)
        ->and($event->pool_fee)->toEqual(5)
        ->and($event->door_prepay_enabled)->toBeTrue()
        ->and($event->showrunner_id)->toBe($showrunner->id)
        ->and($event->host_id)->toBe($host->id)
        ->and($event->notes)->toBe('note here')
        ->and($event->created_by)->toBe($this->admin->id);
});

test('bulk upload skips and logs an unparseable date, unmatched event type, unmatched showrunner, and a bad fee', function () {
    $path = buildEventUploadFixture([
        ['not-a-date', '', '', 'Bad Date', '', 20, 5, '', '', '', ''],
        ['2026-09-01', '', '', 'Bad Type', 'Nonexistent Type', 20, 5, '', '', '', ''],
        ['2026-09-02', '', '', 'Bad Showrunner', '', 20, 5, '', 'nonexistent-user', '', ''],
        ['2026-09-03', '', '', 'Bad Fee', '', 'not-a-number', 5, '', '', '', ''],
    ]);

    try {
        callEventBulkUpload($path)->assertHasNoErrors();
    } finally {
        @unlink($path);
    }

    expect(Event::count())->toBe(0);
});

test('bulk upload is idempotent on the same event_date + name', function () {
    $path = buildEventUploadFixture([
        ['2026-09-01', '', '', 'Fall Social', '', 20, 5, '', '', '', ''],
    ]);

    try {
        callEventBulkUpload($path);
        callEventBulkUpload($path);
    } finally {
        @unlink($path);
    }

    expect(Event::where('name', 'Fall Social')->count())->toBe(1);
});

test('bulk upload prunes the event-uploads directory to the last 4 files', function () {
    $path = buildEventUploadFixture([
        ['2026-09-01', '', '', 'Fall Social', '', 20, 5, '', '', '', ''],
    ]);

    try {
        for ($i = 0; $i < 6; $i++) {
            callEventBulkUpload($path);
        }
    } finally {
        @unlink($path);
    }

    expect(Storage::disk('local')->files('event-uploads'))->toHaveCount(4);
});

test('a manager cannot access the events bulk upload action', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    $path = buildEventUploadFixture([
        ['2026-09-01', '', '', 'Fall Social', '', 20, 5, '', '', '', ''],
    ]);

    try {
        Livewire::test(ListEvents::class)->assertActionHidden('bulkUploadEvents');
    } finally {
        @unlink($path);
    }
});
