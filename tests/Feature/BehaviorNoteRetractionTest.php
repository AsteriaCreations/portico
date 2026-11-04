<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\ActivePatrons;
use App\Filament\Admin\Resources\Members\Pages\EditMember;
use App\Filament\Admin\Resources\Members\RelationManagers\BehaviorNotesRelationManager;
use App\Models\Attendance;
use App\Models\AttendanceBehaviorNote;
use App\Models\BehaviorNoteRetraction;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\User;
use App\Services\BehaviorNoteRetractionService;
use App\Services\VisitRemovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $category = Category::factory()->create(['name' => 'Irregular', 'is_comped' => false]);
    $this->member = Member::factory()->create([
        'category_id' => $category->id,
        'dob' => '1990-01-01',
        'is_banned' => false,
        'on_watchlist' => false,
    ]);
    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $this->attendance = Attendance::factory()->for($this->member)->for($event)->create(['checked_in_at' => now()]);

    $this->author = User::factory()->create(['active' => true, 'role' => Role::Volunteer, 'name' => 'Writer One']);
    $this->note = AttendanceBehaviorNote::factory()->for($this->attendance)->create([
        'note' => 'Wrong member, my mistake.',
        'created_by' => $this->author->id,
    ]);
});

test('a Manager retracts any note from the member page; the note is kept, marked retracted, with who and why', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);

    Livewire::test(BehaviorNotesRelationManager::class, [
        'ownerRecord' => $this->member,
        'pageClass' => EditMember::class,
    ])
        ->callTableAction('retractBehaviorNote', $this->note, data: ['reason' => 'Written about the wrong person.'])
        ->assertHasNoTableActionErrors()
        ->assertCanSeeTableRecords([$this->note])
        ->assertTableActionHidden('retractBehaviorNote', $this->note);

    $retraction = BehaviorNoteRetraction::sole();
    expect($retraction->attendance_behavior_note_id)->toBe($this->note->id)
        ->and($retraction->reason)->toBe('Written about the wrong person.')
        ->and($retraction->retracted_by)->toBe($manager->id)
        ->and(AttendanceBehaviorNote::whereKey($this->note->id)->exists())->toBeTrue()
        ->and($this->note->isRetracted())->toBeTrue();
});

test('a reason is required to retract', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(BehaviorNotesRelationManager::class, [
        'ownerRecord' => $this->member,
        'pageClass' => EditMember::class,
    ])
        ->callTableAction('retractBehaviorNote', $this->note, data: ['reason' => ''])
        ->assertHasTableActionErrors(['reason' => 'required']);

    expect(BehaviorNoteRetraction::exists())->toBeFalse();
});

test('the author retracts their own note from Active Patrons, and it leaves the summary and the counts', function () {
    $other = User::factory()->create(['active' => true, 'role' => Role::Volunteer]);
    AttendanceBehaviorNote::factory()->for($this->attendance)->create(['note' => 'Someone else wrote this.', 'created_by' => $other->id]);
    $this->actingAs($this->author);

    Livewire::test(ActivePatrons::class)
        ->callTableAction('retractBehaviorNoteFromVisit', $this->attendance, data: ['note_id' => $this->note->id, 'reason' => 'My mistake.'])
        ->assertHasNoTableActionErrors();

    expect($this->note->isRetracted())->toBeTrue();

    // The author's own note is gone; the other volunteer's still shows as a bare count.
    Livewire::test(ActivePatrons::class)
        ->assertTableColumnStateSet('behaviorNotesSummary', ['1 behavior note'], $this->attendance);

    // Manager+ no longer sees the retracted note on Active Patrons either.
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));
    Livewire::test(ActivePatrons::class)
        ->assertTableColumnStateSet('behaviorNotesSummary', ["Someone else wrote this. — {$other->name}"], $this->attendance);
});

test('another volunteer, DM or Door cannot retract a note they did not write, even via the service', function (Role $role) {
    $user = User::factory()->create(['active' => true, 'role' => $role]);
    $this->actingAs($user);

    expect(Gate::allows('retract-behavior-note', $this->note))->toBeFalse();

    Livewire::test(ActivePatrons::class)
        ->assertTableActionHidden('retractBehaviorNoteFromVisit', $this->attendance);

    expect(fn () => app(BehaviorNoteRetractionService::class)->retract($this->note, $user, 'Forged.'))
        ->toThrow(HttpException::class);
    expect(BehaviorNoteRetraction::exists())->toBeFalse();
})->with([Role::Volunteer, Role::DM, Role::Door]);

test('Manager, Admin and Owner can retract any note; the author can retract their own', function () {
    foreach ([Role::Manager, Role::Admin, Role::Owner] as $role) {
        expect(Gate::forUser(User::factory()->create(['active' => true, 'role' => $role]))->allows('retract-behavior-note', $this->note))->toBeTrue();
    }

    expect(Gate::forUser($this->author)->allows('retract-behavior-note', $this->note))->toBeTrue();
});

test('a note can be retracted only once', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    app(BehaviorNoteRetractionService::class)->retract($this->note, $manager, 'First.');

    expect(Gate::forUser($manager)->allows('retract-behavior-note', $this->note))->toBeFalse()
        ->and(fn () => app(BehaviorNoteRetractionService::class)->retract($this->note, $manager, 'Second.'))->toThrow(HttpException::class)
        ->and(BehaviorNoteRetraction::count())->toBe(1);
});

test('with the behavior-notes flag off, nobody can retract', function () {
    MembershipSetting::current()->update(['behavior_notes_enabled' => false]);
    $owner = User::factory()->create(['active' => true, 'role' => Role::Owner]);

    expect(Gate::forUser($owner)->allows('retract-behavior-note', $this->note))->toBeFalse()
        ->and(Gate::forUser($this->author)->allows('retract-behavior-note', $this->note))->toBeFalse();
});

test('a retracted note still keeps its visit from being removed', function () {
    $owner = User::factory()->create(['active' => true, 'role' => Role::Owner]);
    app(BehaviorNoteRetractionService::class)->retract($this->note, $owner, 'Retracted.');

    expect(app(VisitRemovalService::class)->refusalReason($this->attendance->fresh(), $owner))->not->toBeNull();
});

test('the BehaviorNoteRetractionPolicy forbids create, update, and delete for every role, including Owner', function () {
    $owner = User::factory()->create(['active' => true, 'role' => Role::Owner]);
    $retraction = BehaviorNoteRetraction::factory()->create(['attendance_behavior_note_id' => $this->note->id]);

    expect($owner->can('create', BehaviorNoteRetraction::class))->toBeFalse()
        ->and($owner->can('update', $retraction))->toBeFalse()
        ->and($owner->can('delete', $retraction))->toBeFalse()
        ->and($owner->can('deleteAny', BehaviorNoteRetraction::class))->toBeFalse()
        ->and($owner->can('viewAny', BehaviorNoteRetraction::class))->toBeTrue();
});
