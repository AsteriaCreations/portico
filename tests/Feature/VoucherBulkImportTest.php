<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Vouchers\Pages\ListVouchers;
use App\Models\Member;
use App\Models\User;
use App\Models\Voucher;
use App\Services\VoucherBulkImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['active' => true, 'role' => Role::Admin]);
    $this->actingAs($this->admin);
});

function buildVoucherUploadFixture(array $rows): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();

    $sheet->fromArray(['member_number_or_username', 'amount', 'reason'], null, 'A1');

    foreach ($rows as $i => $row) {
        $sheet->fromArray($row, null, 'A'.($i + 2));
    }

    $path = tempnam(sys_get_temp_dir(), 'voucher-upload-').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

function callVoucherBulkUpload(array $rows)
{
    $path = buildVoucherUploadFixture($rows);

    try {
        return Livewire::test(ListVouchers::class)
            ->callAction('bulkUploadVouchers', data: ['file' => UploadedFile::fake()->createWithContent(basename($path), file_get_contents($path))]);
    } finally {
        @unlink($path);
    }
}

test('bulk upload issues a voucher per row, matching members by username or member number', function () {
    $byUsername = Member::factory()->create(['username' => 'jsmith']);
    $byNumber = Member::factory()->create();

    callVoucherBulkUpload([
        ['jsmith', '25', 'Volunteer thank-you'],
        [(string) $byNumber->member_number, '10.50', 'Raffle prize'],
    ])->assertHasNoErrors();

    $voucher = Voucher::where('member_id', $byUsername->id)->sole();
    expect($voucher->amount)->toEqual(25)
        ->and($voucher->reason)->toBe('Volunteer thank-you')
        ->and($voucher->recorded_by)->toBe($this->admin->id)
        ->and(Voucher::where('member_id', $byNumber->id)->sole()->amount)->toEqual(10.5);
});

test('a negative amount is accepted as a correction, like the single-voucher form', function () {
    $member = Member::factory()->create(['username' => 'jsmith']);

    callVoucherBulkUpload([['jsmith', '-25', 'Void wrongly-issued grant']]);

    expect(Voucher::where('member_id', $member->id)->sole()->amount)->toEqual(-25);
});

test('bad rows are skipped and logged, never guessed', function () {
    Member::factory()->create(['username' => 'jsmith']);

    callVoucherBulkUpload([
        ['nobody', '25', 'Unknown member'],
        ['jsmith', '0', 'Zero amount'],
        ['jsmith', 'ten', 'Not a number'],
        ['jsmith', '1.234', 'Too many decimals'],
        ['jsmith', '25', ''],
        ['', '25', 'No member'],
        ['', '', ''],
    ])->assertNotified();

    expect(Voucher::count())->toBe(0);
});

test('re-uploading the same file never credits anyone twice', function () {
    $member = Member::factory()->create(['username' => 'jsmith']);
    $rows = [['jsmith', '25', 'Volunteer thank-you, September 2026']];

    callVoucherBulkUpload($rows);
    callVoucherBulkUpload($rows);

    expect(Voucher::where('member_id', $member->id)->count())->toBe(1);

    callVoucherBulkUpload([['jsmith', '25', 'Volunteer thank-you, October 2026']]);

    expect(Voucher::where('member_id', $member->id)->count())->toBe(2);
});

test('the template and upload are Admin+ only, like issuing a voucher by hand', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(ListVouchers::class)
        ->assertActionHidden('downloadVoucherTemplate')
        ->assertActionHidden('bulkUploadVouchers');
});

test('the template downloads with the columns the importer reads', function () {
    Livewire::test(ListVouchers::class)
        ->assertActionVisible('downloadVoucherTemplate')
        ->callAction('downloadVoucherTemplate')
        ->assertFileDownloaded('voucher-upload-template-'.now()->toDateString().'.csv');
});

function callVoucherFileCheck(array $rows)
{
    $path = buildVoucherUploadFixture($rows);

    try {
        return Livewire::test(ListVouchers::class)
            ->callAction('checkVoucherFile', data: ['file' => UploadedFile::fake()->createWithContent(basename($path), file_get_contents($path))]);
    } finally {
        @unlink($path);
    }
}

test('checking a file issues nothing and downloads a results file', function () {
    Member::factory()->create(['username' => 'jsmith']);

    callVoucherFileCheck([['jsmith', '25', 'Volunteer thank-you']])
        ->assertHasNoErrors()
        ->assertNotified()
        ->assertFileDownloaded('voucher-upload-check-'.now()->toDateString().'.csv');

    expect(Voucher::count())->toBe(0);
});

test('the check reports each row with its matched member and the same problems the upload would skip', function () {
    $member = Member::factory()->create(['username' => 'jsmith']);
    Voucher::factory()->create(['member_id' => $member->id, 'amount' => 5, 'reason' => 'Already issued']);

    $path = buildVoucherUploadFixture([
        ['jsmith', '25', 'Volunteer thank-you'],
        [(string) $member->member_number, '25', 'Volunteer thank-you'],
        ['nobody', '25', 'Unknown member'],
        ['jsmith', '1.234', 'Too many decimals'],
        ['jsmith', '25', ''],
        ['jsmith', '5', 'Already issued'],
        ['', '', ''],
    ]);

    try {
        $rows = app(VoucherBulkImporter::class)->check($path);
    } finally {
        @unlink($path);
    }

    expect(collect($rows)->map(fn (array $row) => [$row['row'], $row['member'], $row['problem']])->all())->toBe([
        [2, 'jsmith', null],
        [3, 'jsmith', 'same member, amount and reason as row 2'],
        [4, null, "no member found for identifier 'nobody'"],
        [5, 'jsmith', "amount '1.234' must be a non-zero number with at most 2 decimals"],
        [6, 'jsmith', 'blank reason'],
        [7, 'jsmith', "jsmith already has a 5.00 voucher for 'Already issued'"],
    ])->and(Voucher::count())->toBe(1);
});

test('checking a file is Admin+ only, like the upload', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(ListVouchers::class)->assertActionHidden('checkVoucherFile');
});
