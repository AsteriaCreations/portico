<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\CompReasons\Pages\ListCompReasons;
use App\Models\CompReason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));
});

function buildCompReasonUploadFixture(array $rows): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();

    $sheet->fromArray(['name', 'description', 'grants_voucher_amount', 'sort_order', 'active'], null, 'A1');

    foreach ($rows as $i => $row) {
        $sheet->fromArray($row, null, 'A'.($i + 2));
    }

    $path = tempnam(sys_get_temp_dir(), 'comp-reason-upload-').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

function callCompReasonBulkUpload(string $path)
{
    return Livewire::test(ListCompReasons::class)
        ->callAction('bulkUploadCompReasons', data: ['file' => UploadedFile::fake()->createWithContent(basename($path), file_get_contents($path))]);
}

test('bulk upload creates a valid row', function () {
    $path = buildCompReasonUploadFixture([
        ['House Sub', 'Worked the event in place of staff', '25', '10', 'N'],
    ]);

    try {
        callCompReasonBulkUpload($path)->assertHasNoErrors();
    } finally {
        @unlink($path);
    }

    $reason = CompReason::where('name', 'House Sub')->firstOrFail();
    expect($reason->description)->toBe('Worked the event in place of staff')
        ->and($reason->grants_voucher_amount)->toEqual(25)
        ->and($reason->sort_order)->toBe(10)
        ->and($reason->active)->toBeFalse();
});

test('bulk upload skips and logs a duplicate name and a bad voucher amount, and blank rows use defaults', function () {
    CompReason::factory()->create(['name' => 'Existing']);

    $path = buildCompReasonUploadFixture([
        ['Existing', '', '', '', ''],
        ['BadAmount', '', 'not-a-number', '', ''],
        ['Defaulted', '', '', '', ''],
    ]);

    try {
        callCompReasonBulkUpload($path)->assertHasNoErrors();
    } finally {
        @unlink($path);
    }

    expect(CompReason::where('name', 'Existing')->count())->toBe(1)
        ->and(CompReason::where('name', 'BadAmount')->exists())->toBeFalse();

    $defaulted = CompReason::where('name', 'Defaulted')->firstOrFail();
    expect($defaulted->grants_voucher_amount)->toBeNull()
        ->and($defaulted->sort_order)->toBe(0)
        ->and($defaulted->active)->toBeTrue();
});

test('re-running the same bulk upload is idempotent', function () {
    $path = buildCompReasonUploadFixture([
        ['House Sub', '', '', '', ''],
    ]);

    try {
        callCompReasonBulkUpload($path);
        callCompReasonBulkUpload($path);
    } finally {
        @unlink($path);
    }

    expect(CompReason::where('name', 'House Sub')->count())->toBe(1);
});
