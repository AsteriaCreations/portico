<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Skills\Pages\ListSkills;
use App\Models\Member;
use App\Models\Skill;
use App\Models\User;
use App\Services\MemberSkillBulkImporter;
use App\Services\SkillBulkImporter;
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

function buildSkillUploadFixture(array $header, array $rows): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();

    $sheet->fromArray($header, null, 'A1');

    foreach ($rows as $i => $row) {
        $sheet->fromArray($row, null, 'A'.($i + 2));
    }

    $path = tempnam(sys_get_temp_dir(), 'skill-upload-').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

function callSkillPageAction(string $action, array $header, array $rows)
{
    $path = buildSkillUploadFixture($header, $rows);

    try {
        return Livewire::test(ListSkills::class)
            ->callAction($action, data: ['file' => UploadedFile::fake()->createWithContent(basename($path), file_get_contents($path))]);
    } finally {
        @unlink($path);
    }
}

const SKILL_COLUMNS = ['name', 'description', 'sort_order', 'active'];
const MEMBER_SKILL_COLUMNS = ['member_number_or_username', 'skill'];

test('the skills upload adds catalog entries with their description, sort order and active flag', function () {
    callSkillPageAction('bulkUploadSkills', SKILL_COLUMNS, [
        ['First aid', 'Current certificate on file', '10', 'yes'],
        ['Rigging', '', '', 'no'],
        ['Sound', '', '', ''],
    ])->assertHasNoErrors();

    $firstAid = Skill::where('name', 'First aid')->sole();
    expect($firstAid->description)->toBe('Current certificate on file')
        ->and($firstAid->sort_order)->toBe(10)
        ->and($firstAid->active)->toBeTrue()
        ->and(Skill::where('name', 'Rigging')->sole()->active)->toBeFalse()
        ->and(Skill::where('name', 'Sound')->sole()->sort_order)->toBe(0);
});

test('bad skill rows are skipped and logged, and the check agrees with the upload', function () {
    Skill::factory()->create(['name' => 'Sound']);

    $path = buildSkillUploadFixture(SKILL_COLUMNS, [
        ['Rigging', '', '', ''],
        ['rigging', '', '', ''],
        ['SOUND', '', '', ''],
        ['', 'No name', '', ''],
        [str_repeat('x', 61), '', '', ''],
        ['Lighting', '', 'first', ''],
        ['Lighting', '', '', 'maybe'],
        ['', '', '', ''],
    ]);

    try {
        $rows = app(SkillBulkImporter::class)->check($path);
        expect(Skill::count())->toBe(1);

        $result = app(SkillBulkImporter::class)->import($path);
    } finally {
        @unlink($path);
    }

    expect(collect($rows)->map(fn (array $row) => [$row['row'], $row['problem']])->all())->toBe([
        [2, null],
        [3, "'rigging' is already in this file at row 2"],
        [4, "'SOUND' is already in the Skills catalog"],
        [5, 'blank name'],
        [6, 'name longer than 60 characters'],
        [7, "sort order 'first' must be a whole number"],
        [8, "unrecognized active value 'maybe'"],
    ])->and($result['created'])->toBe(1)
        ->and(Skill::count())->toBe(2);
});

test('the member skill upload tags members, matched by username or member number', function () {
    $firstAid = Skill::factory()->create(['name' => 'First aid', 'active' => true]);
    $alice = Member::factory()->create(['username' => 'alice']);
    $bob = Member::factory()->create();

    callSkillPageAction('bulkUploadMemberSkills', MEMBER_SKILL_COLUMNS, [
        ['alice', 'first aid'],
        [(string) $bob->member_number, 'First aid'],
    ])->assertHasNoErrors();

    expect($alice->skills()->pluck('skills.id')->all())->toBe([$firstAid->id])
        ->and($bob->skills()->pluck('skills.id')->all())->toBe([$firstAid->id]);
});

test('bad member skill rows are skipped and logged, and tags are only ever added', function () {
    $firstAid = Skill::factory()->create(['name' => 'First aid', 'active' => true]);
    $sound = Skill::factory()->create(['name' => 'Sound', 'active' => true]);
    Skill::factory()->create(['name' => 'Retired', 'active' => false]);
    $alice = Member::factory()->create(['username' => 'alice']);
    $carol = Member::factory()->create(['username' => 'carol']);
    $carol->skills()->attach($sound->id);

    $path = buildSkillUploadFixture(MEMBER_SKILL_COLUMNS, [
        ['alice', 'First aid'],
        ['alice', 'first aid'],
        ['carol', 'Sound'],
        ['nobody', 'First aid'],
        ['alice', 'Retired'],
        ['alice', ''],
        ['', 'First aid'],
        ['carol', 'First aid'],
    ]);

    try {
        $rows = app(MemberSkillBulkImporter::class)->check($path);
        expect($alice->skills()->count())->toBe(0);

        $result = app(MemberSkillBulkImporter::class)->import($path);
    } finally {
        @unlink($path);
    }

    expect(collect($rows)->map(fn (array $row) => [$row['row'], $row['member'], $row['problem']])->all())->toBe([
        [2, 'alice', null],
        [3, 'alice', 'same member and skill as row 2'],
        [4, 'carol', 'carol already has Sound'],
        [5, null, "no member found for identifier 'nobody'"],
        [6, 'alice', "no active skill named 'Retired'"],
        [7, 'alice', 'blank skill'],
        [8, null, 'blank member identifier'],
        [9, 'carol', null],
    ])->and($result['created'])->toBe(2)
        ->and($carol->skills()->pluck('skills.id')->sort()->values()->all())->toBe(collect([$firstAid->id, $sound->id])->sort()->values()->all());
});

test('re-uploading either file never changes anything twice', function () {
    Skill::factory()->create(['name' => 'First aid', 'active' => true]);
    Member::factory()->create(['username' => 'alice']);

    callSkillPageAction('bulkUploadSkills', SKILL_COLUMNS, [['Rigging', '', '', '']]);
    callSkillPageAction('bulkUploadSkills', SKILL_COLUMNS, [['Rigging', '', '', '']]);
    callSkillPageAction('bulkUploadMemberSkills', MEMBER_SKILL_COLUMNS, [['alice', 'First aid']]);
    callSkillPageAction('bulkUploadMemberSkills', MEMBER_SKILL_COLUMNS, [['alice', 'First aid']]);

    expect(Skill::where('name', 'Rigging')->count())->toBe(1)
        ->and(Member::where('username', 'alice')->sole()->skills()->count())->toBe(1);
});

test('templates download, and checks change nothing and download a results file', function () {
    Skill::factory()->create(['name' => 'First aid', 'active' => true]);
    Member::factory()->create(['username' => 'alice']);

    Livewire::test(ListSkills::class)
        ->callAction('downloadSkillTemplate')
        ->assertFileDownloaded('skill-upload-template-'.now()->toDateString().'.csv')
        ->callAction('downloadMemberSkillTemplate')
        ->assertFileDownloaded('member-skill-upload-template-'.now()->toDateString().'.csv');

    callSkillPageAction('checkSkillFile', SKILL_COLUMNS, [['Rigging', '', '', '']])
        ->assertNotified()
        ->assertFileDownloaded('skill-upload-check-'.now()->toDateString().'.csv');

    callSkillPageAction('checkMemberSkillFile', MEMBER_SKILL_COLUMNS, [['alice', 'First aid']])
        ->assertNotified()
        ->assertFileDownloaded('member-skill-upload-check-'.now()->toDateString().'.csv');

    expect(Skill::count())->toBe(1)
        ->and(Member::where('username', 'alice')->sole()->skills()->count())->toBe(0);
});

test('a Manager can upload skills but not tag members, which stays Admin+', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(ListSkills::class)
        ->assertActionVisible('bulkUploadSkills')
        ->assertActionHidden('downloadMemberSkillTemplate')
        ->assertActionHidden('checkMemberSkillFile')
        ->assertActionHidden('bulkUploadMemberSkills');
});
