<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\User;
use App\Services\UserBulkImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['active' => true, 'role' => Role::Admin]);
    $this->actingAs($this->admin);
});

function buildUserUploadFixture(array $rows): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();

    $sheet->fromArray(['name', 'email', 'role', 'member_number_or_username'], null, 'A1');

    foreach ($rows as $i => $row) {
        $sheet->fromArray($row, null, 'A'.($i + 2));
    }

    $path = tempnam(sys_get_temp_dir(), 'user-upload-').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

function callUserBulkUpload(array $rows)
{
    $path = buildUserUploadFixture($rows);

    try {
        return Livewire::test(ListUsers::class)
            ->callAction('bulkUploadUsers', data: ['file' => UploadedFile::fake()->createWithContent(basename($path), file_get_contents($path))]);
    } finally {
        @unlink($path);
    }
}

/**
 * @return array{created: list<array{name: string, email: string, password: string}>, log: string[]}
 */
function importUsersAs(User $actor, array $rows): array
{
    $path = buildUserUploadFixture($rows);

    try {
        return app(UserBulkImporter::class)->import($path, $actor);
    } finally {
        @unlink($path);
    }
}

test('bulk upload creates an account per row with a temporary password it must change', function () {
    $member = Member::factory()->create(['username' => 'jsmith']);

    callUserBulkUpload([
        ['Jane Smith', 'jane@example.com', 'door', 'jsmith'],
        ['Sam Lee', 'sam@example.com', 'Manager', ''],
    ])->assertHasNoErrors()->assertNotified();

    $jane = User::where('email', 'jane@example.com')->sole();
    expect($jane->name)->toBe('Jane Smith')
        ->and($jane->role)->toBe(Role::Door)
        ->and($jane->member_id)->toBe($member->id)
        ->and($jane->active)->toBeTrue()
        ->and($jane->must_change_password)->toBeTrue()
        ->and(User::where('email', 'sam@example.com')->sole()->role)->toBe(Role::Manager);
});

test('each new account gets its own working temporary password, returned once', function () {
    $result = importUsersAs($this->admin, [
        ['Jane Smith', 'jane@example.com', 'door', ''],
        ['Sam Lee', 'sam@example.com', 'door', ''],
    ]);

    expect($result['created'])->toHaveCount(2)
        ->and($result['created'][0]['password'])->not->toBe($result['created'][1]['password']);

    foreach ($result['created'] as $created) {
        expect(Hash::check($created['password'], User::where('email', $created['email'])->sole()->password))->toBeTrue();
    }
});

test('a role matches by its club alias as well as its value or generic label', function () {
    $settings = MembershipSetting::current();
    $settings->role_labels = ['showrunner' => 'Show Captain'];
    $settings->save();

    importUsersAs($this->admin, [
        ['Alias', 'alias@example.com', 'show captain', ''],
        ['Generic', 'generic@example.com', 'Event Lead', ''],
    ]);

    expect(User::where('email', 'alias@example.com')->sole()->role)->toBe(Role::Showrunner)
        ->and(User::where('email', 'generic@example.com')->sole()->role)->toBe(Role::Showrunner);
});

test('bad rows are skipped and logged, never guessed', function () {
    $linked = Member::factory()->create(['username' => 'taken']);
    User::factory()->create(['member_id' => $linked->id, 'email' => 'existing@example.com']);

    $result = importUsersAs($this->admin, [
        ['', 'noname@example.com', 'door', ''],
        ['Bad Email', 'not-an-email', 'door', ''],
        ['Dup', 'EXISTING@example.com', 'door', ''],
        ['No Role', 'norole@example.com', '', ''],
        ['Odd Role', 'oddrole@example.com', 'wizard', ''],
        ['No Member', 'nomember@example.com', 'door', 'nobody'],
        ['Linked', 'linked@example.com', 'door', 'taken'],
        ['', '', '', ''],
    ]);

    expect($result['created'])->toBe([])
        ->and($result['log'])->toHaveCount(7)
        ->and(User::count())->toBe(2);
});

test('nobody creates an account above their own role', function () {
    User::factory()->create(['active' => true, 'role' => Role::Owner]);

    $result = importUsersAs($this->admin, [['Boss', 'boss@example.com', 'owner', '']]);

    expect($result['created'])->toBe([])
        ->and(User::where('email', 'boss@example.com')->exists())->toBeFalse();
});

test('with no active Owner, an Admin can create the first one but not a second', function () {
    importUsersAs($this->admin, [
        ['First', 'first@example.com', 'owner', ''],
        ['Second', 'second@example.com', 'owner', ''],
    ]);

    expect(User::where('email', 'first@example.com')->sole()->role)->toBe(Role::Owner)
        ->and(User::where('email', 'second@example.com')->exists())->toBeFalse();
});

test('the temporary passwords download once as a CSV', function () {
    $this->travelTo(now()->setTime(14, 30, 5));

    $component = callUserBulkUpload([['Jane Smith', 'jane@example.com', 'door', '']])
        ->assertFileDownloaded('new-user-passwords-'.now()->format('Y-m-d-His').'.csv');

    $rows = array_map('str_getcsv', explode("\n", trim(base64_decode($component->effects['download']['content']))));

    expect($rows[0])->toBe(['name', 'email', 'temporary_password'])
        ->and($rows[1][0])->toBe('Jane Smith')
        ->and($rows[1][1])->toBe('jane@example.com')
        ->and(Hash::check($rows[1][2], User::where('email', 'jane@example.com')->sole()->password))->toBeTrue();
});

test('nothing downloads when no account was created', function () {
    callUserBulkUpload([['', 'noname@example.com', 'door', '']])
        ->assertNoFileDownloaded()
        ->assertNotified();
});

test('re-uploading the same file never creates anyone twice', function () {
    $rows = [['Jane Smith', 'jane@example.com', 'door', '']];

    callUserBulkUpload($rows);
    callUserBulkUpload($rows);

    expect(User::where('email', 'jane@example.com')->count())->toBe(1);
});

test('the template and upload are Admin+ only, like creating a user by hand', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    $this->get(ListUsers::getUrl())->assertForbidden();
    expect(Gate::forUser(auth()->user())->allows('create', User::class))->toBeFalse();
});

test('the template downloads with the columns the importer reads', function () {
    Livewire::test(ListUsers::class)
        ->assertActionVisible('downloadUserTemplate')
        ->assertActionVisible('bulkUploadUsers')
        ->callAction('downloadUserTemplate')
        ->assertFileDownloaded('user-upload-template-'.now()->toDateString().'.csv');
});
