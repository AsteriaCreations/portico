<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Categories\Pages\ListCategories;
use App\Models\Category;
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

function buildCategoryUploadFixture(array $rows): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();

    $sheet->fromArray(['name', 'description', 'is_comped', 'sort_order', 'active'], null, 'A1');

    foreach ($rows as $i => $row) {
        $sheet->fromArray($row, null, 'A'.($i + 2));
    }

    $path = tempnam(sys_get_temp_dir(), 'category-upload-').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

function callCategoryBulkUpload(string $path)
{
    return Livewire::test(ListCategories::class)
        ->callAction('bulkUploadCategories', data: ['file' => UploadedFile::fake()->createWithContent(basename($path), file_get_contents($path))]);
}

test('bulk upload creates a valid row', function () {
    $path = buildCategoryUploadFixture([
        ['Sponsor', 'Sponsoring member category', 'Y', '10', 'N'],
    ]);

    try {
        callCategoryBulkUpload($path)->assertHasNoErrors();
    } finally {
        @unlink($path);
    }

    $category = Category::where('name', 'Sponsor')->firstOrFail();
    expect($category->description)->toBe('Sponsoring member category')
        ->and($category->is_comped)->toBeTrue()
        ->and($category->sort_order)->toBe(10)
        ->and($category->active)->toBeFalse();
});

test('bulk upload skips and logs a duplicate name and a bad boolean, and blank rows use defaults', function () {
    Category::factory()->create(['name' => 'Existing']);

    $path = buildCategoryUploadFixture([
        ['Existing', '', '', '', ''],
        ['BadFlag', '', 'maybe', '', ''],
        ['Defaulted', '', '', '', ''],
    ]);

    try {
        callCategoryBulkUpload($path)->assertHasNoErrors();
    } finally {
        @unlink($path);
    }

    expect(Category::where('name', 'Existing')->count())->toBe(1)
        ->and(Category::where('name', 'BadFlag')->exists())->toBeFalse();

    $defaulted = Category::where('name', 'Defaulted')->firstOrFail();
    expect($defaulted->is_comped)->toBeFalse()
        ->and($defaulted->sort_order)->toBe(0)
        ->and($defaulted->active)->toBeTrue();
});

test('re-running the same bulk upload is idempotent', function () {
    $path = buildCategoryUploadFixture([
        ['Sponsor', '', '', '', ''],
    ]);

    try {
        callCategoryBulkUpload($path);
        callCategoryBulkUpload($path);
    } finally {
        @unlink($path);
    }

    expect(Category::where('name', 'Sponsor')->count())->toBe(1);
});
