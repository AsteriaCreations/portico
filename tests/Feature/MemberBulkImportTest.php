<?php

use App\Filament\Admin\Resources\Members\Pages\ListMembers;
use App\Models\Category;
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
    $this->actingAs(User::factory()->create(['active' => true]));
});

function buildMemberUploadFixture(array $rows): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();

    $sheet->fromArray([
        'username', 'first_name', 'last_name', 'preferred_name', 'email', 'email_opt_in',
        'category', 'sponsor_username', 'member_number', 'date_vetted', 'dob', 'paperwork_date',
        'is_active', 'subscription_eligible', 'on_watchlist', 'watchlist_reason', 'is_banned',
        'ban_reason', 'probation_override_start', 'missing_paperwork', 'is_deceased',
        'hospitality_note', 'notes',
    ], null, 'A1');

    foreach ($rows as $i => $row) {
        $sheet->fromArray($row, null, 'A'.($i + 2));
    }

    $path = tempnam(sys_get_temp_dir(), 'member-upload-').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

function blankMemberRow(array $overrides = []): array
{
    return array_replace([
        '', '', '', '', '', '',
        '', '', '', '', '', '',
        '', '', '', '', '',
        '', '', '', '',
        '', '',
    ], $overrides);
}

function callMemberBulkUpload(string $path)
{
    return Livewire::test(ListMembers::class)
        ->callAction('bulkUploadMembers', data: ['file' => UploadedFile::fake()->createWithContent(basename($path), file_get_contents($path))]);
}

test('bulk upload creates a valid row, resolving category and sponsor by name/username', function () {
    $category = Category::factory()->create(['name' => 'Regular']);
    $sponsor = Member::factory()->create(['username' => 'sponsor1']);

    $path = buildMemberUploadFixture([
        blankMemberRow([0 => 'jsmith', 1 => 'Jane', 2 => 'Smith', 3 => 'Janie', 4 => 'jane@example.com', 5 => 'Y', 6 => 'Regular', 7 => 'sponsor1', 9 => '2026-01-15', 10 => '1990-05-20', 12 => 'Y']),
    ]);

    try {
        callMemberBulkUpload($path)->assertHasNoErrors();
    } finally {
        @unlink($path);
    }

    $member = Member::where('username', 'jsmith')->firstOrFail();
    expect($member->category_id)->toBe($category->id)
        ->and($member->sponsor_id)->toBe($sponsor->id)
        ->and($member->preferred_name)->toBe('Janie')
        ->and($member->email)->toBe('jane@example.com')
        ->and($member->email_opt_in)->toBeTrue()
        ->and($member->date_vetted->toDateString())->toBe('2026-01-15')
        ->and($member->dob->toDateString())->toBe('1990-05-20')
        ->and($member->is_active)->toBeTrue()
        ->and($member->is_banned)->toBeFalse();
});

test('member_number is left blank for auto-assignment when not supplied', function () {
    Category::factory()->create(['name' => 'Regular']);
    Member::factory()->create(['member_number' => 7]);

    $path = buildMemberUploadFixture([
        blankMemberRow([0 => 'newperson', 6 => 'Regular']),
    ]);

    try {
        callMemberBulkUpload($path)->assertHasNoErrors();
    } finally {
        @unlink($path);
    }

    expect(Member::where('username', 'newperson')->firstOrFail()->member_number)->toBe(8);
});

test('bulk upload skips and logs a blank username, duplicate username, unmatched category, unmatched sponsor, bad boolean, and bad date', function () {
    Category::factory()->create(['name' => 'Regular']);
    Member::factory()->create(['username' => 'existing']);

    $path = buildMemberUploadFixture([
        blankMemberRow([1 => 'No', 2 => 'Username', 6 => 'Regular']), // blank username
        blankMemberRow([0 => 'existing', 6 => 'Regular']), // duplicate username
        blankMemberRow([0 => 'nocategory', 6 => 'Nonexistent Category']),
        blankMemberRow([0 => 'badsponsor', 6 => 'Regular', 7 => 'nonexistent-user']),
        blankMemberRow([0 => 'badbool', 6 => 'Regular', 12 => 'maybe']),
        blankMemberRow([0 => 'baddate', 6 => 'Regular', 10 => 'not-a-date']),
    ]);

    try {
        callMemberBulkUpload($path)->assertHasNoErrors();
    } finally {
        @unlink($path);
    }

    expect(Member::count())->toBe(1); // only the pre-existing 'existing' member
});

test('re-running the same bulk upload is idempotent', function () {
    Category::factory()->create(['name' => 'Regular']);

    $path = buildMemberUploadFixture([
        blankMemberRow([0 => 'jsmith', 6 => 'Regular']),
    ]);

    try {
        callMemberBulkUpload($path);
        callMemberBulkUpload($path);
    } finally {
        @unlink($path);
    }

    expect(Member::where('username', 'jsmith')->count())->toBe(1);
});

test('bulk upload prunes the member-uploads directory to the last 4 files', function () {
    Category::factory()->create(['name' => 'Regular']);

    $path = buildMemberUploadFixture([
        blankMemberRow([0 => 'jsmith', 6 => 'Regular']),
    ]);

    try {
        for ($i = 0; $i < 6; $i++) {
            callMemberBulkUpload($path);
        }
    } finally {
        @unlink($path);
    }

    expect(Storage::disk('local')->files('member-uploads'))->toHaveCount(4);
});
