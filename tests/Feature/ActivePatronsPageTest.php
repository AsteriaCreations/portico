<?php

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
use App\Models\Skill;
use App\Models\User;
use App\Services\CapacityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;

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

test('clicking the member column dispatches the depart action, not a nonexistent depart() method', function () {
    $volunteer = User::factory()->create(['active' => true, 'role' => Role::Volunteer]);
    $this->actingAs($volunteer);

    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $member = clearMemberForActivePatrons($this->irregular, ['username' => 'click-target']);
    $attendance = Attendance::factory()->for($member)->for($event)->create(['checked_in_at' => now()]);

    $html = Livewire::test(ActivePatrons::class)->html();

    // Column::action() with a bare Action instance renders a
    // mountTableAction(...) wire:click, the same working mechanism
    // recordActions() uses. Passing a string instead (the original bug)
    // renders callTableColumnAction(...), which calls $this->depart($record)
    // as a literal method — nonexistent here — and throws
    // BadMethodCallException the moment a real click reaches the server.
    expect($html)->toContain('mountTableAction(&#039;depart&#039;')
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

test('the watchlist icon renders for a watchlisted member and the watchlist-only filter narrows the table', function () {
    $volunteer = User::factory()->create(['active' => true, 'role' => Role::Volunteer]);
    $this->actingAs($volunteer);

    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $watchlisted = clearMemberForActivePatrons($this->irregular, ['username' => 'flagged-one', 'on_watchlist' => true]);
    $clear = clearMemberForActivePatrons($this->irregular, ['username' => 'clear-one']);

    $flaggedAttendance = Attendance::factory()->for($watchlisted)->for($event)->create(['checked_in_at' => now()]);
    $clearAttendance = Attendance::factory()->for($clear)->for($event)->create(['checked_in_at' => now()]);

    Livewire::test(ActivePatrons::class)
        ->assertCanSeeTableRecords([$flaggedAttendance, $clearAttendance])
        ->filterTable('watchlist_only', true)
        ->assertCanSeeTableRecords([$flaggedAttendance])
        ->assertCanNotSeeTableRecords([$clearAttendance]);
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

test('the Overnight column reflects an overnight stay correctly', function () {
    $volunteer = User::factory()->create(['active' => true, 'role' => Role::Volunteer]);
    $this->actingAs($volunteer);

    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $overnight = clearMemberForActivePatrons($this->irregular, ['username' => 'overnight-icon']);
    $ordinary = clearMemberForActivePatrons($this->irregular, ['username' => 'ordinary-icon']);

    $overnightAttendance = Attendance::factory()->for($overnight)->for($event)->create(['checked_in_at' => now()]);
    attachOvernightAddOn($overnightAttendance);
    $ordinaryAttendance = Attendance::factory()->for($ordinary)->for($event)->create(['checked_in_at' => now()]);

    Livewire::test(ActivePatrons::class)
        ->assertTableColumnStateSet('overnight', true, $overnightAttendance)
        ->assertTableColumnStateSet('overnight', false, $ordinaryAttendance);
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
        ->assertTableColumnStateSet('behaviorNotesSummary', 'Aggressive toward staff at the door.', $attendance);

    // A different Volunteer sees only a count, never the text.
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Volunteer]));

    Livewire::test(ActivePatrons::class)
        ->assertTableColumnStateSet('behaviorNotesSummary', '1 behavior note', $attendance);

    // DM and Door see every note's full text, but not who wrote it.
    foreach ([Role::DM, Role::Door] as $role) {
        $this->actingAs(User::factory()->create(['active' => true, 'role' => $role]));

        Livewire::test(ActivePatrons::class)
            ->assertTableColumnStateSet('behaviorNotesSummary', 'Aggressive toward staff at the door.', $attendance);
    }

    // Manager+ sees full text and who wrote it, regardless of authorship.
    foreach ([Role::Manager, Role::Admin, Role::Owner] as $role) {
        $this->actingAs(User::factory()->create(['active' => true, 'role' => $role]));

        Livewire::test(ActivePatrons::class)
            ->assertTableColumnStateSet('behaviorNotesSummary', "Aggressive toward staff at the door. — {$author->name}", $attendance);
    }
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

    // The column is hidden, but the underlying Action carries no ->visible()
    // of its own (only the column does) -- callTableAction() reaches the
    // closure the same way a forged request would, so the closure's own
    // abort_unless(Gate::allows('manage-visit-notes')) is what's actually
    // under test here, not Filament's client-side hiding.
    Livewire::test(ActivePatrons::class)
        ->callTableAction('editVisitNote', $attendance, data: ['visit_note' => 'Forged note']);

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
        ->callTableAction('addBehaviorNote', $attendance, data: ['note' => 'Should not be saved']);

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
