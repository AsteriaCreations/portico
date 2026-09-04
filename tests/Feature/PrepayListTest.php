<?php

use App\Enums\AddOnKind;
use App\Enums\Role;
use App\Filament\Admin\Resources\Events\Pages\EditEvent;
use App\Filament\Admin\Resources\Events\RelationManagers\PrepayListRelationManager;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($this->manager);

    // PricingService::price() always resolves the entry target -- present
    // in a real install via AddOnSeeder, seeded directly here for this
    // test's minimal fixture.
    AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
});

function buildPrepayUploadFixture(array $rows): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();

    foreach ($rows as $i => [$identifier, $override]) {
        $row = $i + 1;
        $sheet->setCellValue("A{$row}", $identifier);
        $sheet->setCellValue("B{$row}", $override);
    }

    $path = tempnam(sys_get_temp_dir(), 'prepay-list-').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

test('a manager can add a single member to the prepay list, priced via PricingService', function () {
    $event = Event::factory()->create(['entry_fee' => 40, 'pool_fee' => 0]);
    $member = Member::factory()->create();

    Livewire::test(PrepayListRelationManager::class, [
        'ownerRecord' => $event,
        'pageClass' => EditEvent::class,
    ])
        ->callTableAction('create', data: ['member_id' => $member->id])
        ->assertHasNoTableActionErrors();

    $attendance = Attendance::where('event_id', $event->id)->where('member_id', $member->id)->firstOrFail();
    expect($attendance->checked_in_at)->toBeNull()
        ->and($attendance->entry_fee)->toEqual(40)
        ->and($attendance->amount_paid)->toEqual(40);
});

test('the prepay list tab is hidden once prepay_enabled is off, restored once re-enabled', function () {
    MembershipSetting::current()->update(['prepay_enabled' => false]);
    $event = Event::factory()->create();

    expect(PrepayListRelationManager::canViewForRecord($event, EditEvent::class))->toBeFalse();

    MembershipSetting::current()->update(['prepay_enabled' => true]);

    expect(PrepayListRelationManager::canViewForRecord($event, EditEvent::class))->toBeTrue();
});

test('an amount override replaces amount_paid only, leaving entry_fee/coverage computed normally', function () {
    $event = Event::factory()->create(['entry_fee' => 40, 'pool_fee' => 0]);
    $member = Member::factory()->create();

    Livewire::test(PrepayListRelationManager::class, [
        'ownerRecord' => $event,
        'pageClass' => EditEvent::class,
    ])
        ->callTableAction('create', data: [
            'member_id' => $member->id,
            'amount_override' => 20,
        ])
        ->assertHasNoTableActionErrors();

    $attendance = Attendance::where('event_id', $event->id)->where('member_id', $member->id)->firstOrFail();
    expect($attendance->entry_fee)->toEqual(40)
        ->and($attendance->amount_paid)->toEqual(20);
});

test('the prepay list table excludes an already-arrived attendance row', function () {
    $event = Event::factory()->create();
    $arrived = Attendance::factory()->for($event)->create(['checked_in_at' => now()]);
    $prepaid = Attendance::factory()->for($event)->create(['checked_in_at' => null]);

    Livewire::test(PrepayListRelationManager::class, [
        'ownerRecord' => $event,
        'pageClass' => EditEvent::class,
    ])
        ->assertCanSeeTableRecords([$prepaid])
        ->assertCanNotSeeTableRecords([$arrived]);
});

test('adding to the prepay list is rejected once the building is at capacity', function () {
    MembershipSetting::current()->update(['venue_capacity' => 1]);
    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    Attendance::factory()->for($event)->create(['checked_in_at' => now()]);

    $newMember = Member::factory()->create();

    Livewire::test(PrepayListRelationManager::class, [
        'ownerRecord' => $event,
        'pageClass' => EditEvent::class,
    ])
        ->callTableAction('create', data: ['member_id' => $newMember->id])
        ->assertHasTableActionErrors();

    expect(Attendance::where('event_id', $event->id)->where('member_id', $newMember->id)->exists())->toBeFalse();
});

test('bulk upload creates rows, skips an unmatched identifier and a duplicate, and applies overrides', function () {
    $event = Event::factory()->create(['entry_fee' => 40, 'pool_fee' => 0]);
    $alice = Member::factory()->create(['username' => 'alice']);
    $bob = Member::factory()->create(['username' => 'bob', 'member_number' => 42]);
    $alreadyListed = Member::factory()->create(['username' => 'carol']);
    Attendance::factory()->for($event)->for($alreadyListed)->create(['checked_in_at' => null]);

    $path = buildPrepayUploadFixture([
        ['alice', null],
        [42, 20], // bob, matched by member_number, with an amount override
        ['nonexistent-user', null],
        ['carol', null], // already on the list
    ]);

    try {
        Livewire::test(PrepayListRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => EditEvent::class,
        ])
            ->callTableAction('bulkUploadPrepay', data: ['file' => UploadedFile::fake()->createWithContent(basename($path), file_get_contents($path))])
            ->assertHasNoTableActionErrors();
    } finally {
        @unlink($path);
    }

    $aliceAttendance = Attendance::where('event_id', $event->id)->where('member_id', $alice->id)->firstOrFail();
    expect($aliceAttendance->checked_in_at)->toBeNull()
        ->and($aliceAttendance->amount_paid)->toEqual(40);

    $bobAttendance = Attendance::where('event_id', $event->id)->where('member_id', $bob->id)->firstOrFail();
    expect($bobAttendance->amount_paid)->toEqual(20);

    expect(Attendance::where('event_id', $event->id)->count())->toBe(3); // alice, bob, carol (pre-existing) — nonexistent-user skipped
});

test('re-running the same bulk upload is idempotent', function () {
    $event = Event::factory()->create(['entry_fee' => 40]);
    $alice = Member::factory()->create(['username' => 'alice']);

    $path = buildPrepayUploadFixture([['alice', null]]);

    try {
        Livewire::test(PrepayListRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
            ->callTableAction('bulkUploadPrepay', data: ['file' => UploadedFile::fake()->createWithContent(basename($path), file_get_contents($path))]);
        Livewire::test(PrepayListRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
            ->callTableAction('bulkUploadPrepay', data: ['file' => UploadedFile::fake()->createWithContent(basename($path), file_get_contents($path))]);
    } finally {
        @unlink($path);
    }

    expect(Attendance::where('event_id', $event->id)->where('member_id', $alice->id)->count())->toBe(1);
});

test('bulk upload prunes the prepay-uploads directory to the last 4 files', function () {
    $event = Event::factory()->create(['entry_fee' => 40]);
    Member::factory()->create(['username' => 'alice']);

    $path = buildPrepayUploadFixture([['alice', null]]);

    try {
        for ($i = 0; $i < 6; $i++) {
            Livewire::test(PrepayListRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
                ->callTableAction('bulkUploadPrepay', data: ['file' => UploadedFile::fake()->createWithContent(basename($path), file_get_contents($path))]);
        }
    } finally {
        @unlink($path);
    }

    expect(Storage::disk('local')->files('prepay-uploads'))->toHaveCount(4);
});

test('a door volunteer has no access to the events resource at all', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $event = Event::factory()->create();
    $this->actingAs($door);

    $this->get("/admin/events/{$event->id}/edit")->assertForbidden();
});
