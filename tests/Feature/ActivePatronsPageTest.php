<?php

use App\Enums\AddOnKind;
use App\Enums\Role;
use App\Filament\Admin\Pages\ActivePatrons;
use App\Filament\Admin\Resources\Members\Pages\EditMember;
use App\Filament\Admin\Resources\Members\RelationManagers\BehaviorNotesRelationManager;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\AttendanceAddOn;
use App\Models\AttendanceBehaviorNote;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\OccupancyAdjustment;
use App\Models\Skill;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CapacityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

function createSignedInSession(User $user, int $secondsAgo = 0): void
{
    DB::table('sessions')->insert([
        'id' => Str::random(40),
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->subSeconds($secondsAgo)->getTimestamp(),
    ]);
}

beforeEach(function () {
    $this->irregular = Category::factory()->create(['name' => 'Irregular', 'is_comped' => false]);
});

function clearMemberForActivePatrons(Category $category, array $overrides = []): Member
{
    return Member::factory()->create(array_merge([
        'category_id' => $category->id,
        'dob' => '1990-01-01',
        'is_banned' => false,
        'on_watchlist' => false,
    ], $overrides));
}

/**
 * An earlier, finished visit, so tonight isn't the member's first arrival
 * and the "New member" flag stays out of a test about another flag.
 */
function givePriorVisit(Member ...$members): void
{
    $lastWeek = Event::factory()->create(['event_date' => today()->subWeek()->toDateString()]);

    foreach ($members as $member) {
        Attendance::factory()->for($member)->for($lastWeek)->create([
            'checked_in_at' => now()->subWeek(),
            'departed_at' => now()->subWeek()->addHours(3),
        ]);
    }
}

test('the page is accessible to Volunteer and above, but not Showrunner', function () {
    $showrunner = User::factory()->create(['active' => true, 'role' => Role::Showrunner]);
    $this->actingAs($showrunner);
    expect(ActivePatrons::canAccess())->toBeFalse();
    $this->get('/admin/active-patrons')->assertForbidden();

    foreach ([Role::Volunteer, Role::DM, Role::Door, Role::Manager, Role::Admin, Role::Owner] as $role) {
        $user = User::factory()->create(['active' => true, 'role' => $role]);
        $this->actingAs($user);
        expect(ActivePatrons::canAccess())->toBeTrue();
        $this->get('/admin/active-patrons')->assertSuccessful();
    }
});

test('the table lists arrived patrons across concurrent active events, omitting prepays and other days', function () {
    $volunteer = User::factory()->create(['active' => true, 'role' => Role::Volunteer]);
    $this->actingAs($volunteer);

    $eventA = Event::factory()->create(['name' => 'Friday Social', 'event_date' => today()->toDateString()]);
    $eventB = Event::factory()->create(['name' => 'Friday Class', 'event_date' => today()->toDateString()]);
    $otherDay = Event::factory()->create(['name' => 'Last Week', 'event_date' => today()->subWeek()->toDateString()]);

    $arrivedA = clearMemberForActivePatrons($this->irregular, ['username' => 'arrived-a']);
    $arrivedB = clearMemberForActivePatrons($this->irregular, ['username' => 'arrived-b']);
    $prepay = clearMemberForActivePatrons($this->irregular, ['username' => 'prepay-only']);
    $elsewhere = clearMemberForActivePatrons($this->irregular, ['username' => 'other-day']);

    Attendance::factory()->for($arrivedA)->for($eventA)->create(['checked_in_at' => now()]);
    Attendance::factory()->for($arrivedB)->for($eventB)->create(['checked_in_at' => now()]);
    Attendance::factory()->for($prepay)->for($eventA)->create(['checked_in_at' => null]);
    Attendance::factory()->for($elsewhere)->for($otherDay)->create(['checked_in_at' => now()]);

    $livewire = Livewire::test(ActivePatrons::class);

    $livewire->assertCanSeeTableRecords(Attendance::whereIn('member_id', [$arrivedA->id, $arrivedB->id])->get())
        ->assertCanNotSeeTableRecords(Attendance::whereIn('member_id', [$prepay->id, $elsewhere->id])->get());

    expect($livewire->instance()->getActiveEvents()->pluck('id')->all())
        ->toEqualCanonicalizing([$eventA->id, $eventB->id]);
});

test('departing marks departed_at on exactly that attendance row and it drops off the table', function () {
    $volunteer = User::factory()->create(['active' => true, 'role' => Role::Volunteer]);
    $this->actingAs($volunteer);

    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $member = clearMemberForActivePatrons($this->irregular, ['username' => 'leaving-now']);
    $otherMember = clearMemberForActivePatrons($this->irregular, ['username' => 'staying-put']);

    $attendance = Attendance::factory()->for($member)->for($event)->create(['checked_in_at' => now()]);
    $otherAttendance = Attendance::factory()->for($otherMember)->for($event)->create(['checked_in_at' => now()]);

    Livewire::test(ActivePatrons::class)
        ->callTableAction('depart', $attendance)
        ->assertHasNoTableActionErrors();

    expect($attendance->refresh()->departed_at)->not->toBeNull()
        ->and($otherAttendance->refresh()->departed_at)->toBeNull();

    Livewire::test(ActivePatrons::class)
        ->assertCanNotSeeTableRecords([$attendance])
        ->assertCanSeeTableRecords([$otherAttendance]);
});

test('the roster renders as a responsive stacked layout, not one wide row of columns', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Volunteer]));

    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $member = clearMemberForActivePatrons($this->irregular, ['username' => 'layout-check']);
    Attendance::factory()->for($member)->for($event)->create(['checked_in_at' => now()]);

    // Split::make() emits a `fi-ta-split` wrapper; a plain ->columns([TextColumn, ...])
    // table never does. This is what keeps the phone view off a 10-wide horizontal scroll.
    expect(Livewire::test(ActivePatrons::class)->html())->toContain('fi-ta-split');
});

test('clicking the member column dispatches the depart action, not a nonexistent depart() method', function () {
    $volunteer = User::factory()->create(['active' => true, 'role' => Role::Volunteer]);
    $this->actingAs($volunteer);

    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $member = clearMemberForActivePatrons($this->irregular, ['username' => 'click-target']);
    $attendance = Attendance::factory()->for($member)->for($event)->create(['checked_in_at' => now()]);

    $html = Livewire::test(ActivePatrons::class)->html();

    // Column::action() with a bare Action instance renders a real action
    // mount on click -- mountAction('depart', ...) for a column nested in a
    // Split/Stack layout (the record-action Depart button separately renders
    // mountTableAction('depart', ...); both dispatch to the same table
    // action). Passing a string instead (the original bug) renders
    // callTableColumnAction(...), which calls $this->depart($record) as a
    // literal method — nonexistent here — and throws BadMethodCallException
    // the moment a real click reaches the server.
    expect($html)->toContain("mountAction('depart'")
        ->not->toContain('callTableColumnAction');
});

test('departing reduces CapacityService occupancy', function () {
    $volunteer = User::factory()->create(['active' => true, 'role' => Role::Volunteer]);
    $this->actingAs($volunteer);

    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $member = clearMemberForActivePatrons($this->irregular, ['username' => 'leaving-now']);
    $attendance = Attendance::factory()->for($member)->for($event)->create(['checked_in_at' => now()]);

    expect(app(CapacityService::class)->occupancy(today()))->toBe(1);

    Livewire::test(ActivePatrons::class)->callTableAction('depart', $attendance);

    expect(app(CapacityService::class)->occupancy(today()))->toBe(0);
});

test('the watchlist badge renders for a watchlisted member and the watchlist-only filter narrows the table', function () {
    $volunteer = User::factory()->create(['active' => true, 'role' => Role::Volunteer]);
    $this->actingAs($volunteer);

    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $watchlisted = clearMemberForActivePatrons($this->irregular, ['username' => 'flagged-one', 'on_watchlist' => true]);
    $clear = clearMemberForActivePatrons($this->irregular, ['username' => 'clear-one']);
    givePriorVisit($watchlisted, $clear);

    $flaggedAttendance = Attendance::factory()->for($watchlisted)->for($event)->create(['checked_in_at' => now()]);
    $clearAttendance = Attendance::factory()->for($clear)->for($event)->create(['checked_in_at' => now()]);

    Livewire::test(ActivePatrons::class)
        ->assertTableColumnStateSet('flags', ['Watchlist'], $flaggedAttendance)
        ->assertTableColumnStateSet('flags', null, $clearAttendance)
        ->assertCanSeeTableRecords([$flaggedAttendance, $clearAttendance])
        ->filterTable('watchlist_only', true)
        ->assertCanSeeTableRecords([$flaggedAttendance])
        ->assertCanNotSeeTableRecords([$clearAttendance]);
});

test('a member on watchlist probation gets a Recently off watchlist badge, which ends with the probation', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Volunteer]));
    MembershipSetting::current()->update(['watchlist_probation_mode' => 'custom', 'watchlist_probation_days' => 30]);

    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $recent = clearMemberForActivePatrons($this->irregular, ['username' => 'recent-one', 'watchlist_probation_start' => today()->subDays(29)]);
    $longAgo = clearMemberForActivePatrons($this->irregular, ['username' => 'longago-one', 'watchlist_probation_start' => today()->subDays(30)]);
    givePriorVisit($recent, $longAgo);

    $recentAttendance = Attendance::factory()->for($recent)->for($event)->create(['checked_in_at' => now()]);
    $longAgoAttendance = Attendance::factory()->for($longAgo)->for($event)->create(['checked_in_at' => now()]);

    Livewire::test(ActivePatrons::class)
        ->assertTableColumnStateSet('flags', ['Recently off watchlist'], $recentAttendance)
        ->assertTableColumnStateSet('flags', null, $longAgoAttendance);
});

function attachOvernightAddOn(Attendance $attendance): void
{
    $addOn = AddOn::factory()->create(['name' => 'Sleepover', 'is_overnight' => true]);
    AttendanceAddOn::create([
        'attendance_id' => $attendance->id,
        'add_on_id' => $addOn->id,
        'name' => $addOn->name,
        'price' => $addOn->price,
        'is_overnight' => true,
    ]);
}

test('an overnight-flagged, non-departed patron stays visible after their event drops out of currentQuery(), while an ordinary one does not', function () {
    $volunteer = User::factory()->create(['active' => true, 'role' => Role::Volunteer]);
    $this->actingAs($volunteer);

    $event = Event::factory()->create([
        'event_date' => '2026-07-18',
        'starts_at' => '2026-07-18 20:00:00',
        'ends_at' => '2026-07-18 23:00:00',
    ]);

    $this->travelTo(Carbon::parse('2026-07-18 21:00:00'));
    $overnight = clearMemberForActivePatrons($this->irregular, ['username' => 'staying-over']);
    $ordinary = clearMemberForActivePatrons($this->irregular, ['username' => 'left-already']);

    $overnightAttendance = Attendance::factory()->for($overnight)->for($event)->create(['checked_in_at' => now(), 'departed_at' => null]);
    attachOvernightAddOn($overnightAttendance);
    $ordinaryAttendance = Attendance::factory()->for($ordinary)->for($event)->create(['checked_in_at' => now(), 'departed_at' => null]);

    // Well past ends_at plus the default buffer.
    $this->travelTo(Carbon::parse('2026-07-20 12:00:00'));

    Livewire::test(ActivePatrons::class)
        ->assertCanSeeTableRecords([$overnightAttendance])
        ->assertCanNotSeeTableRecords([$ordinaryAttendance]);
});

test('the flags column badges Overnight only for an overnight stay', function () {
    $volunteer = User::factory()->create(['active' => true, 'role' => Role::Volunteer]);
    $this->actingAs($volunteer);

    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $overnight = clearMemberForActivePatrons($this->irregular, ['username' => 'overnight-icon']);
    $ordinary = clearMemberForActivePatrons($this->irregular, ['username' => 'ordinary-icon']);
    givePriorVisit($overnight, $ordinary);

    $overnightAttendance = Attendance::factory()->for($overnight)->for($event)->create(['checked_in_at' => now()]);
    attachOvernightAddOn($overnightAttendance);
    $ordinaryAttendance = Attendance::factory()->for($ordinary)->for($event)->create(['checked_in_at' => now()]);

    Livewire::test(ActivePatrons::class)
        ->assertTableColumnStateSet('flags', ['Overnight'], $overnightAttendance)
        ->assertTableColumnStateSet('flags', null, $ordinaryAttendance);
});

test('the flags column badges Guest for a guest and New member for an Irregular on their first visit', function () {
    $volunteer = User::factory()->create(['active' => true, 'role' => Role::Volunteer]);
    $this->actingAs($volunteer);

    $guestCategory = Category::factory()->create(['name' => 'Guest', 'is_comped' => false]);
    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $sponsor = clearMemberForActivePatrons($this->irregular, ['username' => 'sponsor-one']);
    $guest = clearMemberForActivePatrons($guestCategory, ['username' => 'guest-one', 'sponsor_id' => $sponsor->id]);
    $newMember = clearMemberForActivePatrons($this->irregular, ['username' => 'new-one']);
    givePriorVisit($sponsor, $guest);

    $sponsorAttendance = Attendance::factory()->for($sponsor)->for($event)->create(['checked_in_at' => now()]);
    $guestAttendance = Attendance::factory()->for($guest)->for($event)->create(['checked_in_at' => now()]);
    $newMemberAttendance = Attendance::factory()->for($newMember)->for($event)->create(['checked_in_at' => now()]);

    Livewire::test(ActivePatrons::class)
        ->assertTableColumnStateSet('flags', ['Guest'], $guestAttendance)
        ->assertTableColumnStateSet('flags', ['New member'], $newMemberAttendance)
        ->assertTableColumnStateSet('flags', null, $sponsorAttendance);
});

test('the flags column badges New subscription only for a subscription paid for with this visit, and names a guest\'s sponsor on hover', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Volunteer]));

    $entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    $guestCategory = Category::factory()->create(['name' => 'Guest', 'is_comped' => false]);
    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $buyer = clearMemberForActivePatrons($this->irregular, ['username' => 'buyer']);
    $oldSubscriber = clearMemberForActivePatrons($this->irregular, ['username' => 'oldsub']);
    $perk = clearMemberForActivePatrons($this->irregular, ['username' => 'perk']);
    $sponsor = clearMemberForActivePatrons($this->irregular, ['username' => 'host', 'preferred_name' => 'Hosty']);
    $guest = clearMemberForActivePatrons($guestCategory, ['username' => 'visitor', 'sponsor_id' => $sponsor->id]);
    givePriorVisit($buyer, $oldSubscriber, $perk, $sponsor, $guest);

    // Bought last week, before tonight's visit: no badge.
    $this->travel(-7)->days();
    Subscription::factory()->create(['member_id' => $oldSubscriber->id, 'add_on_id' => $entry->id, 'amount_paid' => 60]);
    $this->travelBack();

    $attendances = collect([$buyer, $oldSubscriber, $perk, $guest])
        ->mapWithKeys(fn (Member $member): array => [$member->username => Attendance::factory()->for($member)->for($event)->create(['checked_in_at' => now()])]);
    Subscription::factory()->create(['member_id' => $buyer->id, 'add_on_id' => $entry->id, 'amount_paid' => 60]);
    // A $0 subscription (e.g. the Manager perk) wasn't paid for.
    Subscription::factory()->create(['member_id' => $perk->id, 'add_on_id' => $entry->id, 'amount_paid' => 0, 'voucher_coverage' => 0]);

    Livewire::test(ActivePatrons::class)
        ->assertTableColumnStateSet('flags', ['New subscription'], $attendances['buyer'])
        ->assertTableColumnStateSet('flags', null, $attendances['oldsub'])
        ->assertTableColumnStateSet('flags', null, $attendances['perk'])
        ->assertTableColumnStateSet('flags', ['Guest'], $attendances['visitor'])
        ->assertSee('Guest of Hosty');
});

test('departing an overnight guest removes them from the table just like an ordinary patron', function () {
    $volunteer = User::factory()->create(['active' => true, 'role' => Role::Volunteer]);
    $this->actingAs($volunteer);

    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $overnight = clearMemberForActivePatrons($this->irregular, ['username' => 'depart-overnight']);
    $attendance = Attendance::factory()->for($overnight)->for($event)->create(['checked_in_at' => now(), 'departed_at' => null]);
    attachOvernightAddOn($attendance);

    Livewire::test(ActivePatrons::class)
        ->callTableAction('depart', $attendance)
        ->assertHasNoTableActionErrors();

    expect($attendance->refresh()->departed_at)->not->toBeNull();

    Livewire::test(ActivePatrons::class)->assertCanNotSeeTableRecords([$attendance]);
});

test('the watchlist reason column is visible to Manager+ but hidden from Volunteer and Door', function () {
    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $watchlisted = clearMemberForActivePatrons($this->irregular, ['username' => 'flagged-one', 'on_watchlist' => true, 'watchlist_reason' => 'Prior incident']);
    Attendance::factory()->for($watchlisted)->for($event)->create(['checked_in_at' => now()]);

    foreach ([Role::Volunteer, Role::DM, Role::Door] as $role) {
        $this->actingAs(User::factory()->create(['active' => true, 'role' => $role]));

        Livewire::test(ActivePatrons::class)->assertTableColumnHidden('member.watchlist_reason');
    }

    foreach ([Role::Manager, Role::Admin, Role::Owner] as $role) {
        $this->actingAs(User::factory()->create(['active' => true, 'role' => $role]));

        Livewire::test(ActivePatrons::class)->assertTableColumnVisible('member.watchlist_reason');
    }
});

test('a Volunteer can set and overwrite a visit note, visible to Volunteer, Door, and Manager alike', function () {
    $volunteer = User::factory()->create(['active' => true, 'role' => Role::Volunteer]);
    $this->actingAs($volunteer);

    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $member = clearMemberForActivePatrons($this->irregular, ['username' => 'clothing-note']);
    $attendance = Attendance::factory()->for($member)->for($event)->create(['checked_in_at' => now()]);

    Livewire::test(ActivePatrons::class)
        ->callTableAction('editVisitNote', $attendance, data: ['visit_note' => 'Red jacket, under-21 wristband'])
        ->assertHasNoTableActionErrors();

    expect($attendance->refresh()->visit_note)->toBe('Red jacket, under-21 wristband');

    foreach ([Role::Volunteer, Role::DM, Role::Door, Role::Manager] as $role) {
        $this->actingAs(User::factory()->create(['active' => true, 'role' => $role]));

        Livewire::test(ActivePatrons::class)
            ->assertTableColumnStateSet('visit_note', 'Red jacket, under-21 wristband', $attendance);
    }

    Livewire::test(ActivePatrons::class)
        ->callTableAction('editVisitNote', $attendance, data: ['visit_note' => 'Changed into a black hoodie'])
        ->assertHasNoTableActionErrors();

    expect($attendance->refresh()->visit_note)->toBe('Changed into a black hoodie');
});

test('behavior notes: the author sees their own note in full, another volunteer sees only a count, DM/Door see full text without authorship, and Manager+ sees everything with authorship', function () {
    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $member = clearMemberForActivePatrons($this->irregular, ['username' => 'behavior-flagged']);
    $attendance = Attendance::factory()->for($member)->for($event)->create(['checked_in_at' => now()]);

    $author = User::factory()->create(['active' => true, 'role' => Role::Volunteer, 'name' => 'Writer One']);
    $this->actingAs($author);

    Livewire::test(ActivePatrons::class)
        ->callTableAction('addBehaviorNote', $attendance, data: ['note' => 'Aggressive toward staff at the door.'])
        ->assertHasNoTableActionErrors();

    $note = AttendanceBehaviorNote::sole();
    expect($note->attendance_id)->toBe($attendance->id)
        ->and($note->created_by)->toBe($author->id);

    // The author sees their own note in full.
    Livewire::test(ActivePatrons::class)
        ->assertTableColumnStateSet('behaviorNotesSummary', ['Aggressive toward staff at the door.'], $attendance);

    // A different Volunteer sees only a count, never the text.
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Volunteer]));

    Livewire::test(ActivePatrons::class)
        ->assertTableColumnStateSet('behaviorNotesSummary', ['1 behavior note'], $attendance);

    // DM and Door see every note's full text, but not who wrote it.
    foreach ([Role::DM, Role::Door] as $role) {
        $this->actingAs(User::factory()->create(['active' => true, 'role' => $role]));

        Livewire::test(ActivePatrons::class)
            ->assertTableColumnStateSet('behaviorNotesSummary', ['Aggressive toward staff at the door.'], $attendance);
    }

    // Manager+ sees full text and who wrote it, regardless of authorship.
    foreach ([Role::Manager, Role::Admin, Role::Owner] as $role) {
        $this->actingAs(User::factory()->create(['active' => true, 'role' => $role]));

        Livewire::test(ActivePatrons::class)
            ->assertTableColumnStateSet('behaviorNotesSummary', ["Aggressive toward staff at the door. — {$author->name}"], $attendance);
    }
});

test('several behavior notes on one visit each get their own line, newest first', function () {
    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $member = clearMemberForActivePatrons($this->irregular, ['username' => 'two-notes']);
    $attendance = Attendance::factory()->for($member)->for($event)->create(['checked_in_at' => now()]);

    $writer = User::factory()->create(['active' => true, 'role' => Role::Volunteer, 'name' => 'Writer One']);
    $other = User::factory()->create(['active' => true, 'role' => Role::Volunteer, 'name' => 'Writer Two']);
    AttendanceBehaviorNote::factory()->for($attendance)->create(['note' => 'First note.', 'created_by' => $writer->id, 'created_at' => now()->subMinutes(10)]);
    AttendanceBehaviorNote::factory()->for($attendance)->create(['note' => 'Second note.', 'created_by' => $writer->id, 'created_at' => now()->subMinutes(5)]);
    AttendanceBehaviorNote::factory()->for($attendance)->create(['note' => 'Someone else.', 'created_by' => $other->id, 'created_at' => now()]);

    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::DM]));
    Livewire::test(ActivePatrons::class)
        ->assertTableColumnStateSet('behaviorNotesSummary', ['Someone else.', 'Second note.', 'First note.'], $attendance)
        ->assertSeeHtml('Second note.')
        ->assertDontSeeHtml("Second note.\nFirst note.");

    // The writer sees only their own two, plus a count of the rest.
    $this->actingAs($writer);
    Livewire::test(ActivePatrons::class)
        ->assertTableColumnStateSet('behaviorNotesSummary', ['Second note.', 'First note.', '(+1 more)'], $attendance);
});

test('a behavior note is still reachable by Manager+ on the member profile after the patron departs', function () {
    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $member = clearMemberForActivePatrons($this->irregular, ['username' => 'reviewed-later']);
    $attendance = Attendance::factory()->for($member)->for($event)->create(['checked_in_at' => now(), 'departed_at' => now()]);
    $note = AttendanceBehaviorNote::factory()->for($attendance)->create();

    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);

    expect($member->refresh()->behaviorNotes()->pluck('attendance_behavior_notes.id')->all())->toEqual([$note->id]);

    Livewire::test(BehaviorNotesRelationManager::class, [
        'ownerRecord' => $member,
        'pageClass' => EditMember::class,
    ])->assertCanSeeTableRecords([$note]);
});

test('the AttendanceBehaviorNotePolicy forbids create, update, and delete for every role, including Owner', function () {
    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $member = clearMemberForActivePatrons($this->irregular, ['username' => 'policy-check']);
    $attendance = Attendance::factory()->for($member)->for($event)->create(['checked_in_at' => now()]);
    $note = AttendanceBehaviorNote::factory()->for($attendance)->create();

    foreach ([Role::Volunteer, Role::DM, Role::Door, Role::Manager, Role::Admin, Role::Owner] as $role) {
        $user = User::factory()->create(['active' => true, 'role' => $role]);

        expect(Gate::forUser($user)->allows('create', AttendanceBehaviorNote::class))->toBeFalse()
            ->and(Gate::forUser($user)->allows('update', $note))->toBeFalse()
            ->and(Gate::forUser($user)->allows('delete', $note))->toBeFalse()
            ->and(Gate::forUser($user)->allows('deleteAny', AttendanceBehaviorNote::class))->toBeFalse();
    }

    foreach ([Role::Volunteer, Role::DM, Role::Door] as $role) {
        $user = User::factory()->create(['active' => true, 'role' => $role]);

        expect(Gate::forUser($user)->allows('viewAny', AttendanceBehaviorNote::class))->toBeFalse();
    }

    foreach ([Role::Manager, Role::Admin, Role::Owner] as $role) {
        $user = User::factory()->create(['active' => true, 'role' => $role]);

        expect(Gate::forUser($user)->allows('viewAny', AttendanceBehaviorNote::class))->toBeTrue();
    }
});

test('the visit note and behavior notes columns are hidden when their flags are off, and reappear once restored', function () {
    MembershipSetting::current()->update(['visit_notes_enabled' => false, 'behavior_notes_enabled' => false]);

    $volunteer = User::factory()->create(['active' => true, 'role' => Role::Volunteer]);
    $this->actingAs($volunteer);

    Livewire::test(ActivePatrons::class)
        ->assertTableColumnHidden('visit_note')
        ->assertTableColumnHidden('behaviorNotesSummary');

    MembershipSetting::current()->update(['visit_notes_enabled' => true, 'behavior_notes_enabled' => true]);

    Livewire::test(ActivePatrons::class)
        ->assertTableColumnVisible('visit_note')
        ->assertTableColumnVisible('behaviorNotesSummary');
});

test('a forged visit note edit is rejected once visit_notes_enabled is off, leaving the note unchanged', function () {
    MembershipSetting::current()->update(['visit_notes_enabled' => false]);

    $volunteer = User::factory()->create(['active' => true, 'role' => Role::Volunteer]);
    $this->actingAs($volunteer);

    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $member = clearMemberForActivePatrons($this->irregular, ['username' => 'flag-off-visit-note']);
    $attendance = Attendance::factory()->for($member)->for($event)->create(['checked_in_at' => now(), 'visit_note' => 'Original note']);

    // The action is hidden server-side, so Filament refuses to run it for a
    // forged request; the closure's own abort_unless() is a second layer.
    Livewire::test(ActivePatrons::class)
        ->assertTableActionHidden('editVisitNote', $attendance);

    expect($attendance->refresh()->visit_note)->toBe('Original note');
});

test('a forged behavior note add is rejected once behavior_notes_enabled is off, creating no row', function () {
    MembershipSetting::current()->update(['behavior_notes_enabled' => false]);

    $volunteer = User::factory()->create(['active' => true, 'role' => Role::Volunteer]);
    $this->actingAs($volunteer);

    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $member = clearMemberForActivePatrons($this->irregular, ['username' => 'flag-off-behavior-note']);
    $attendance = Attendance::factory()->for($member)->for($event)->create(['checked_in_at' => now()]);

    Livewire::test(ActivePatrons::class)
        ->assertTableActionHidden('addBehaviorNote', $attendance);

    expect(AttendanceBehaviorNote::where('attendance_id', $attendance->id)->exists())->toBeFalse();
});

test('a behavior note written while the flag was on stays reviewable by Manager+ after the flag is turned off', function () {
    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $member = clearMemberForActivePatrons($this->irregular, ['username' => 'flag-toggled-later']);
    $attendance = Attendance::factory()->for($member)->for($event)->create(['checked_in_at' => now()]);
    $note = AttendanceBehaviorNote::factory()->for($attendance)->create();

    MembershipSetting::current()->update(['behavior_notes_enabled' => false]);

    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);

    Livewire::test(BehaviorNotesRelationManager::class, [
        'ownerRecord' => $member,
        'pageClass' => EditMember::class,
    ])->assertCanSeeTableRecords([$note]);
});

test('a member\'s skills render as badges on Active Patrons, visible to Volunteer and absent for a member with none', function () {
    $volunteer = User::factory()->create(['active' => true, 'role' => Role::Volunteer]);
    $this->actingAs($volunteer);

    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $skilled = clearMemberForActivePatrons($this->irregular, ['username' => 'has-skills']);
    $skilled->skills()->attach([
        Skill::factory()->create(['name' => 'First Aid'])->id,
        Skill::factory()->create(['name' => 'DM Experience'])->id,
    ]);
    $unskilled = clearMemberForActivePatrons($this->irregular, ['username' => 'no-skills']);

    $skilledAttendance = Attendance::factory()->for($skilled)->for($event)->create(['checked_in_at' => now()]);
    $unskilledAttendance = Attendance::factory()->for($unskilled)->for($event)->create(['checked_in_at' => now()]);

    Livewire::test(ActivePatrons::class)
        ->assertTableColumnStateSet('member.skills.name', ['First Aid', 'DM Experience'], $skilledAttendance)
        ->assertTableColumnStateSet('member.skills.name', null, $unskilledAttendance);
});

test('signed-in Volunteer+ staff are noted as in the building, but Showrunner, inactive users, and expired sessions are not', function () {
    $volunteer = User::factory()->create(['active' => true, 'role' => Role::Volunteer, 'name' => 'Vera Volunteer']);
    $dm = User::factory()->create(['active' => true, 'role' => Role::DM, 'name' => 'Dana DM']);
    $showrunner = User::factory()->create(['active' => true, 'role' => Role::Showrunner, 'name' => 'Sam Showrunner']);
    $inactiveManager = User::factory()->create(['active' => false, 'role' => Role::Manager, 'name' => 'Ida Inactive']);
    $staleDoor = User::factory()->create(['active' => true, 'role' => Role::Door, 'name' => 'Dee Stale']);

    createSignedInSession($volunteer);
    createSignedInSession($dm);
    createSignedInSession($showrunner);
    createSignedInSession($inactiveManager);
    createSignedInSession($staleDoor, secondsAgo: (config('session.lifetime') + 5) * 60);

    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    $signedIn = Livewire::test(ActivePatrons::class)->instance()->getSignedInStaff();

    expect($signedIn->pluck('id')->all())->toEqualCanonicalizing([$volunteer->id, $dm->id]);
});

test('the "also in the building" note renders on the page for signed-in staff, and is absent when none are signed in', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(ActivePatrons::class)->assertDontSee('Also in the building');

    $volunteer = User::factory()->create(['active' => true, 'role' => Role::Volunteer, 'name' => 'Vera Volunteer']);
    createSignedInSession($volunteer);

    Livewire::test(ActivePatrons::class)->assertSee('Vera Volunteer (Volunteer)');
});

test('the "also in the building" note lists names only once staff roles are turned off, and roles again once restored', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager, 'name' => 'Mo Manager']));
    $volunteer = User::factory()->create(['active' => true, 'role' => Role::Volunteer, 'name' => 'Vera Volunteer']);
    createSignedInSession($volunteer);

    MembershipSetting::current()->update(['active_patrons_show_staff_roles' => false]);

    Livewire::test(ActivePatrons::class)
        ->assertSee('Vera Volunteer')
        ->assertDontSee('Vera Volunteer (Volunteer)');

    MembershipSetting::current()->update(['active_patrons_show_staff_roles' => true]);

    Livewire::test(ActivePatrons::class)->assertSee('Vera Volunteer (Volunteer)');
});

test('departing an already-counted patron takes them off the list without lowering occupancy again', function () {
    $volunteer = User::factory()->create(['active' => true, 'role' => Role::Volunteer]);
    $this->actingAs($volunteer);

    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $leaver = Attendance::factory()->for(clearMemberForActivePatrons($this->irregular, ['username' => 'left-earlier']))->for($event)->create(['checked_in_at' => now()]);
    Attendance::factory()->for(clearMemberForActivePatrons($this->irregular, ['username' => 'still-here']))->for($event)->create(['checked_in_at' => now()]);

    // The Dashboard's anonymous "1 person left".
    OccupancyAdjustment::create(['for_date' => today(), 'delta' => -1, 'recorded_by' => $volunteer->id]);
    expect(app(CapacityService::class)->occupancy(today()))->toBe(1);

    Livewire::test(ActivePatrons::class)
        ->assertTableActionVisible('departAlreadyCounted', $leaver)
        ->callTableAction('departAlreadyCounted', $leaver)
        ->assertHasNoTableActionErrors()
        ->assertCanNotSeeTableRecords([$leaver]);

    expect($leaver->refresh()->departed_at)->not->toBeNull()
        ->and(app(CapacityService::class)->occupancy(today()))->toBe(1)
        ->and(app(CapacityService::class)->unmatchedDepartures(today()))->toBe(0)
        ->and(OccupancyAdjustment::latest('id')->first()->reason)->toContain('left-earlier');
});

test('depart (already counted) is hidden with no unmatched anonymous departure, and the service refuses it', function () {
    $volunteer = User::factory()->create(['active' => true, 'role' => Role::Volunteer]);
    $this->actingAs($volunteer);

    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $first = Attendance::factory()->for(clearMemberForActivePatrons($this->irregular))->for($event)->create(['checked_in_at' => now()]);
    $second = Attendance::factory()->for(clearMemberForActivePatrons($this->irregular))->for($event)->create(['checked_in_at' => now()]);

    Livewire::test(ActivePatrons::class)->assertTableActionHidden('departAlreadyCounted', $first);

    // One anonymous departure matches one patron, not two.
    OccupancyAdjustment::create(['for_date' => today(), 'delta' => -1, 'recorded_by' => $volunteer->id]);
    app(CapacityService::class)->departAlreadyCounted($first, $volunteer);

    Livewire::test(ActivePatrons::class)->assertTableActionHidden('departAlreadyCounted', $second);

    expect(fn () => app(CapacityService::class)->departAlreadyCounted($second, $volunteer))
        ->toThrow(HttpException::class);
    expect($second->refresh()->departed_at)->toBeNull()
        ->and(app(CapacityService::class)->occupancy(today()))->toBe(1);
});

test('the table shows 25 patrons per page by default, so a busy night does not hide anyone behind paging', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Volunteer]));

    Livewire::test(ActivePatrons::class)->assertSet('tableRecordsPerPage', 25);
});
