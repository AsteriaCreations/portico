<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Members\Pages\CreateMember;
use App\Filament\Admin\Resources\Members\Pages\EditMember;
use App\Filament\Admin\Resources\Members\Pages\ListMembers;
use App\Models\Category;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['active' => true]));
    $this->category = Category::factory()->create();
});

test('creating a member via the admin form auto-assigns member_number, ignoring any submitted value', function () {
    Livewire::test(CreateMember::class)
        ->fillForm([
            'member_number' => 999, // disabled/dehydrated(false) on the form — must be ignored
            'username' => 'newmember',
            'category_id' => $this->category->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $member = Member::where('username', 'newmember')->firstOrFail();
    expect($member->member_number)->toBe(1);
});

test('member_number auto-assignment is sequential across successive admin-created members', function () {
    Member::factory()->create(['member_number' => 7]);

    Livewire::test(CreateMember::class)
        ->fillForm(['username' => 'anothermember', 'category_id' => $this->category->id])
        ->call('create')
        ->assertHasNoFormErrors();

    $member = Member::where('username', 'anothermember')->firstOrFail();
    expect($member->member_number)->toBe(8);
});

test('the email list export includes only opted-in members with an email set', function () {
    Member::factory()->create(['username' => 'included1', 'email' => 'yes1@example.com', 'email_opt_in' => true]);
    Member::factory()->create(['username' => 'optedout', 'email' => 'no@example.com', 'email_opt_in' => false]);
    Member::factory()->create(['username' => 'noemailonfile', 'email' => null, 'email_opt_in' => true]);

    $livewire = Livewire::test(ListMembers::class)
        ->callAction('exportEmailList')
        ->assertFileDownloaded('email-opt-in-list-'.now()->toDateString().'.csv');

    $content = base64_decode(data_get($livewire->effects, 'download.content'));

    expect($content)->toContain('included1')
        ->toContain('yes1@example.com')
        ->not->toContain('optedout')
        ->not->toContain('noemailonfile');
});

test('a manager can bulk-change the category for selected members, leaving unselected members untouched', function () {
    $newCategory = Category::factory()->create();
    $selected = Member::factory()->count(2)->create(['category_id' => $this->category->id]);
    $untouched = Member::factory()->create(['category_id' => $this->category->id]);

    Livewire::test(ListMembers::class)
        ->callTableBulkAction('changeCategory', $selected, data: ['category_id' => $newCategory->id])
        ->assertHasNoTableBulkActionErrors();

    expect($selected->fresh()->pluck('category_id')->unique()->all())->toBe([$newCategory->id])
        ->and($untouched->fresh()->category_id)->toBe($this->category->id);
});

test('the email list export is empty when no member is opted in with an email', function () {
    Member::factory()->create(['email' => 'no@example.com', 'email_opt_in' => false]);

    $livewire = Livewire::test(ListMembers::class)
        ->callAction('exportEmailList')
        ->assertFileDownloaded();

    $content = base64_decode(data_get($livewire->effects, 'download.content'));
    $lines = array_filter(explode("\n", trim($content)));

    expect($lines)->toHaveCount(1); // header row only
});

test('the members table can be filtered by watchlist, banned, and probation status', function () {
    $watchlisted = Member::factory()->create(['category_id' => $this->category->id, 'on_watchlist' => true, 'watchlist_reason' => 'reason']);
    $banned = Member::factory()->create(['category_id' => $this->category->id, 'is_banned' => true, 'ban_reason' => 'reason']);
    $onProbation = Member::factory()->create(['category_id' => $this->category->id, 'date_vetted' => now()->subDays(10)]);
    $clear = Member::factory()->create(['category_id' => $this->category->id]);

    $livewire = Livewire::test(ListMembers::class);

    $livewire->filterTable('on_watchlist', true)
        ->assertCanSeeTableRecords([$watchlisted])
        ->assertCanNotSeeTableRecords([$banned, $onProbation, $clear]);

    $livewire->resetTableFilters()
        ->filterTable('is_banned', true)
        ->assertCanSeeTableRecords([$banned])
        ->assertCanNotSeeTableRecords([$watchlisted, $onProbation, $clear]);

    $livewire->resetTableFilters()
        ->filterTable('on_probation', true)
        ->assertCanSeeTableRecords([$onProbation])
        ->assertCanNotSeeTableRecords([$watchlisted, $banned, $clear]);
});

test('the banned_until field is hidden on the member form once suspensions_enabled is off', function () {
    MembershipSetting::current()->update(['suspensions_enabled' => false]);
    $member = Member::factory()->create(['is_banned' => true, 'ban_reason' => 'x']);

    Livewire::test(EditMember::class, ['record' => $member->getKey()])
        ->assertSchemaComponentExists('banned_until', checkComponentUsing: fn ($component) => ! $component->isVisible());
});

test('the banned_until field is visible on the member form when is_banned is on and suspensions_enabled is on', function () {
    $member = Member::factory()->create(['is_banned' => true, 'ban_reason' => 'x']);

    Livewire::test(EditMember::class, ['record' => $member->getKey()])
        ->assertSchemaComponentExists('banned_until', checkComponentUsing: fn ($component) => $component->isVisible());
});

test('the members table Banned column and filter reflect isCurrentlyBanned(), not the raw column', function () {
    $expiredSuspension = Member::factory()->create([
        'category_id' => $this->category->id,
        'username' => 'expired-suspension',
        'is_banned' => true,
        'ban_reason' => 'x',
        'banned_until' => now()->subMonth()->toDateString(),
    ]);
    $activeSuspension = Member::factory()->create([
        'category_id' => $this->category->id,
        'username' => 'active-suspension',
        'is_banned' => true,
        'ban_reason' => 'x',
        'banned_until' => now()->addMonth()->toDateString(),
    ]);
    $permanentlyBanned = Member::factory()->create([
        'category_id' => $this->category->id,
        'username' => 'permanent-ban',
        'is_banned' => true,
        'ban_reason' => 'x',
        'banned_until' => null,
    ]);

    $livewire = Livewire::test(ListMembers::class);

    $livewire->assertTableColumnStateSet('is_banned', false, $expiredSuspension)
        ->assertTableColumnStateSet('is_banned', true, $activeSuspension)
        ->assertTableColumnStateSet('is_banned', true, $permanentlyBanned);

    $livewire->filterTable('is_banned', true)
        ->assertCanSeeTableRecords([$activeSuspension, $permanentlyBanned])
        ->assertCanNotSeeTableRecords([$expiredSuspension]);

    $livewire->resetTableFilters()
        ->filterTable('is_banned', false)
        ->assertCanSeeTableRecords([$expiredSuspension])
        ->assertCanNotSeeTableRecords([$activeSuspension, $permanentlyBanned]);
});

test('the members table can be filtered by active, deceased, missing paperwork, and subscription eligibility status', function () {
    $inactive = Member::factory()->create(['category_id' => $this->category->id, 'is_active' => false]);
    $deceased = Member::factory()->create(['category_id' => $this->category->id, 'is_deceased' => true]);
    $missingPaperwork = Member::factory()->create(['category_id' => $this->category->id, 'missing_paperwork' => true]);
    $subscriptionEligible = Member::factory()->create(['category_id' => $this->category->id, 'subscription_eligible' => true]);
    $clear = Member::factory()->create(['category_id' => $this->category->id]);

    $livewire = Livewire::test(ListMembers::class);

    $livewire->filterTable('is_active', false)
        ->assertCanSeeTableRecords([$inactive])
        ->assertCanNotSeeTableRecords([$deceased, $missingPaperwork, $subscriptionEligible, $clear]);

    $livewire->resetTableFilters()
        ->filterTable('is_deceased', true)
        ->assertCanSeeTableRecords([$deceased])
        ->assertCanNotSeeTableRecords([$inactive, $missingPaperwork, $subscriptionEligible, $clear]);

    $livewire->resetTableFilters()
        ->filterTable('missing_paperwork', true)
        ->assertCanSeeTableRecords([$missingPaperwork])
        ->assertCanNotSeeTableRecords([$inactive, $deceased, $subscriptionEligible, $clear]);

    $livewire->resetTableFilters()
        ->filterTable('subscription_eligible', true)
        ->assertCanSeeTableRecords([$subscriptionEligible])
        ->assertCanNotSeeTableRecords([$inactive, $deceased, $missingPaperwork, $clear]);
});

test('the member status export includes everyone when no filter is applied', function () {
    Member::factory()->create(['category_id' => $this->category->id, 'username' => 'memberone']);
    Member::factory()->create(['category_id' => $this->category->id, 'username' => 'membertwo']);

    $livewire = Livewire::test(ListMembers::class)
        ->callAction('exportMemberStatus')
        ->assertFileDownloaded('member-status-extract-'.now()->toDateString().'.csv');

    $content = base64_decode(data_get($livewire->effects, 'download.content'));

    expect($content)->toContain('memberone')
        ->toContain('membertwo');
});

test('the member status export reflects the table\'s currently active filter', function () {
    Member::factory()->create(['category_id' => $this->category->id, 'username' => 'bannedmember', 'is_banned' => true, 'ban_reason' => 'Fought at the bar']);
    Member::factory()->create(['category_id' => $this->category->id, 'username' => 'clearmember']);

    $livewire = Livewire::test(ListMembers::class)
        ->filterTable('is_banned', true)
        ->callAction('exportMemberStatus')
        ->assertFileDownloaded();

    $content = base64_decode(data_get($livewire->effects, 'download.content'));

    expect($content)->toContain('bannedmember')
        ->not->toContain('clearmember');
});

test('the member status export includes full sensitive detail, not just the boolean flags', function () {
    Member::factory()->create([
        'category_id' => $this->category->id,
        'username' => 'flaggedmember',
        'is_banned' => true,
        'ban_reason' => 'Fought at the bar',
        'on_watchlist' => true,
        'watchlist_reason' => 'Prior incident',
        'dob' => '1990-05-15',
    ]);

    $livewire = Livewire::test(ListMembers::class)
        ->callAction('exportMemberStatus')
        ->assertFileDownloaded();

    $content = base64_decode(data_get($livewire->effects, 'download.content'));

    expect($content)->toContain('Fought at the bar')
        ->toContain('Prior incident')
        ->toContain('1990-05-15');
});

test('a watchlist toggle without a reason is rejected on the member form', function () {
    Livewire::test(CreateMember::class)
        ->fillForm(['username' => 'flagged', 'category_id' => $this->category->id, 'on_watchlist' => true])
        ->call('create')
        ->assertHasFormErrors(['watchlist_reason']);
});

test('a ban toggle without a reason is rejected on the member form', function () {
    Livewire::test(CreateMember::class)
        ->fillForm(['username' => 'banned', 'category_id' => $this->category->id, 'is_banned' => true])
        ->call('create')
        ->assertHasFormErrors(['ban_reason']);
});

test('watchlist and ban toggles with a reason are accepted', function () {
    Livewire::test(CreateMember::class)
        ->fillForm([
            'username' => 'flaggedwithreason',
            'category_id' => $this->category->id,
            'on_watchlist' => true,
            'watchlist_reason' => 'seen fighting at the door',
            'is_banned' => true,
            'ban_reason' => 'repeated harassment',
        ])
        ->call('create')
        ->assertHasNoFormErrors();
});

test('personal info is masked by default on the members table, and the toggle reveals then re-hides it', function () {
    Member::factory()->create([
        'category_id' => $this->category->id,
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'email' => 'jane@example.com',
        'dob' => Carbon::parse('1990-05-20'),
    ]);

    $livewire = Livewire::test(ListMembers::class)
        ->assertSet('piiHidden', true)
        ->assertDontSee('Jane')
        ->assertDontSee('Smith')
        ->assertDontSee('jane@example.com')
        ->assertDontSee('May 20, 1990');

    $livewire->callAction('togglePiiVisibility')
        ->assertSet('piiHidden', false)
        ->assertSee('Jane')
        ->assertSee('Smith')
        ->assertSee('jane@example.com')
        ->assertSee('May 20, 1990');

    $livewire->callAction('togglePiiVisibility')
        ->assertSet('piiHidden', true)
        ->assertDontSee('Jane')
        ->assertDontSee('jane@example.com');
});

test('the members list starts with personal info shown when the hide-by-default membership setting is off', function () {
    MembershipSetting::current()->update(['hide_member_pii_by_default' => false]);
    Member::factory()->create(['category_id' => $this->category->id, 'first_name' => 'Jane']);

    Livewire::test(ListMembers::class)
        ->assertSet('piiHidden', false)
        ->assertSee('Jane');
});

test('an Admin can assign skills to a member via the edit form', function () {
    $member = Member::factory()->create(['category_id' => $this->category->id]);
    $skillA = Skill::factory()->create(['name' => 'First Aid']);
    $skillB = Skill::factory()->create(['name' => 'DM Experience']);

    Livewire::test(EditMember::class, ['record' => $member->getKey()])
        ->assertSchemaComponentExists('skills', checkComponentUsing: fn ($component) => $component->isVisible())
        ->fillForm(['skills' => [$skillA->id, $skillB->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($member->skills()->pluck('skills.id')->all())->toEqualCanonicalizing([$skillA->id, $skillB->id]);
});

test('a Manager cannot see or assign skills on the member form, even via a forged payload', function () {
    $member = Member::factory()->create(['category_id' => $this->category->id]);
    $skill = Skill::factory()->create();

    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(EditMember::class, ['record' => $member->getKey()])
        ->assertSchemaComponentExists('skills', checkComponentUsing: fn ($component) => ! $component->isVisible())
        ->set('data.skills', [$skill->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($member->skills()->count())->toBe(0);
});

test('a member\'s existing skills survive an unrelated edit by a Manager', function () {
    $member = Member::factory()->create(['category_id' => $this->category->id]);
    $skill = Skill::factory()->create();
    $member->skills()->attach($skill);

    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(EditMember::class, ['record' => $member->getKey()])
        ->fillForm(['hospitality_note' => 'Prefers quiet corner'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($member->skills()->pluck('skills.id')->all())->toEqual([$skill->id]);
});
