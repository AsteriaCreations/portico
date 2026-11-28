<?php

use App\Enums\Role;
use App\Enums\RoleTaskStatus;
use App\Filament\Admin\Resources\RoleTasks\Pages\ListRoleTasks;
use App\Models\MembershipSetting;
use App\Models\RoleTask;
use App\Models\ShiftRole;
use App\Models\TaskArea;
use App\Models\User;
use App\Services\RoleTaskBulkImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

beforeEach(function () {
    MembershipSetting::current()->update(['staff_scheduling_enabled' => true]);

    $this->manager = User::factory()->create(['role' => Role::Manager, 'active' => true]);
    $this->actingAs($this->manager);

    $this->desk = ShiftRole::factory()->create(['name' => 'Desk']);
    $this->cleaning = ShiftRole::factory()->create(['name' => 'Cleaning', 'scheduled' => false]);
});

function buildRoleTaskUploadFixture(array $rows): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();

    $sheet->fromArray(['shift_role', 'task', 'area', 'details', 'sort_order'], null, 'A1');

    foreach ($rows as $i => $row) {
        $sheet->fromArray($row, null, 'A'.($i + 2));
    }

    $path = tempnam(sys_get_temp_dir(), 'role-task-upload-').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

function callRoleTaskAction(string $action, array $rows)
{
    $path = buildRoleTaskUploadFixture($rows);

    try {
        return Livewire::test(ListRoleTasks::class)
            ->callAction($action, data: ['file' => UploadedFile::fake()->createWithContent(basename($path), file_get_contents($path))]);
    } finally {
        @unlink($path);
    }
}

test('bulk upload adds standard tasks, matching roles and areas by name and creating a missing area once', function () {
    $bar = TaskArea::factory()->create(['name' => 'Bar']);

    callRoleTaskAction('bulkUploadRoleTasks', [
        ['cleaning', 'Mop the floor', 'Bathrooms', 'Bleach only', '10'],
        ['Cleaning', 'Restock paper towels', 'bathrooms', '', ''],
        ['Desk', 'Count the float', '', '', '1'],
        ['Cleaning', 'Wipe the counter', 'BAR', '', ''],
    ])->assertHasNoErrors();

    $mop = RoleTask::where('name', 'Mop the floor')->sole();
    expect($mop->shift_role_id)->toBe($this->cleaning->id)
        ->and($mop->status)->toBe(RoleTaskStatus::Standard)
        ->and($mop->active)->toBeTrue()
        ->and($mop->description)->toBe('Bleach only')
        ->and($mop->sort_order)->toBe(10)
        ->and($mop->area->name)->toBe('Bathrooms');

    expect(TaskArea::where('name', 'Bathrooms')->count())->toBe(1)
        ->and(RoleTask::where('name', 'Restock paper towels')->sole()->task_area_id)->toBe($mop->task_area_id)
        ->and(RoleTask::where('name', 'Count the float')->sole()->task_area_id)->toBeNull()
        ->and(RoleTask::where('name', 'Wipe the counter')->sole()->task_area_id)->toBe($bar->id)
        ->and(TaskArea::count())->toBe(2);
});

test('bad rows are skipped and logged, and the check agrees with the upload', function () {
    ShiftRole::factory()->create(['name' => 'Retired', 'active' => false]);
    RoleTask::factory()->create(['shift_role_id' => $this->desk->id, 'name' => 'Count the float']);

    $path = buildRoleTaskUploadFixture([
        ['Desk', 'Open the till', '', '', ''],
        ['Desk', 'open the till', '', '', ''],
        ['Desk', 'count the FLOAT', '', '', ''],
        ['Nobody', 'Anything', '', '', ''],
        ['Retired', 'Anything', '', '', ''],
        ['Desk', '', '', '', ''],
        ['', 'Orphan task', '', '', ''],
        ['Desk', 'Bad order', '', '', 'first'],
        ['Desk', str_repeat('x', 101), '', '', ''],
        ['Cleaning', 'Scrub', 'Typo Area', '', ''],
        ['', '', '', '', ''],
    ]);

    try {
        $rows = app(RoleTaskBulkImporter::class)->check($path);
        expect(RoleTask::count())->toBe(1)->and(TaskArea::count())->toBe(0);

        $result = app(RoleTaskBulkImporter::class)->import($path);
    } finally {
        @unlink($path);
    }

    expect(collect($rows)->map(fn (array $row) => [$row['row'], $row['problem'], $row['note']])->all())->toBe([
        [2, null, null],
        [3, "'open the till' for Desk is already in this file at row 2", null],
        [4, "'count the FLOAT' is already on Desk's list", null],
        [5, "no active shift role named 'Nobody'", null],
        [6, "no active shift role named 'Retired'", null],
        [7, 'blank task', null],
        [8, 'blank shift role', null],
        [9, "sort order 'first' must be a whole number", null],
        [10, 'task longer than 100 characters', null],
        [11, null, "creates area 'Typo Area'"],
    ]);

    expect($result['created'])->toBe(2)
        ->and($result['areas_created'])->toBe(1)
        ->and(RoleTask::count())->toBe(3);
});

test('re-uploading the same file never adds anything twice', function () {
    callRoleTaskAction('bulkUploadRoleTasks', [['Cleaning', 'Mop the floor', 'Bathrooms', '', '']]);
    callRoleTaskAction('bulkUploadRoleTasks', [['Cleaning', 'Mop the floor', 'Bathrooms', '', '']]);

    expect(RoleTask::count())->toBe(1)->and(TaskArea::count())->toBe(1);
});

test('the same task name may go on two different roles', function () {
    callRoleTaskAction('bulkUploadRoleTasks', [
        ['Desk', 'Take out the trash', '', '', ''],
        ['Cleaning', 'Take out the trash', '', '', ''],
    ]);

    expect(RoleTask::where('name', 'Take out the trash')->count())->toBe(2);
});

test('the template downloads, and a check adds nothing and downloads a results file', function () {
    Livewire::test(ListRoleTasks::class)
        ->callAction('downloadRoleTaskTemplate')
        ->assertFileDownloaded('task-upload-template-'.now()->toDateString().'.csv');

    callRoleTaskAction('checkRoleTaskFile', [['Cleaning', 'Mop the floor', 'Bathrooms', '', '']])
        ->assertHasNoErrors()
        ->assertNotified()
        ->assertFileDownloaded('task-upload-check-'.now()->toDateString().'.csv');

    expect(RoleTask::count())->toBe(0)->and(TaskArea::count())->toBe(0);
});

test('the template, check and upload are Manager+, like adding one task by hand', function () {
    $door = User::factory()->create(['role' => Role::Door, 'active' => true]);

    expect($door->can('create', RoleTask::class))->toBeFalse()
        ->and($door->can('create', TaskArea::class))->toBeFalse()
        ->and($this->manager->can('create', RoleTask::class))->toBeTrue();

    Livewire::test(ListRoleTasks::class)
        ->assertActionVisible('downloadRoleTaskTemplate')
        ->assertActionVisible('checkRoleTaskFile')
        ->assertActionVisible('bulkUploadRoleTasks');
});
