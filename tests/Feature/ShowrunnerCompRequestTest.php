<?php

use App\Enums\AddOnKind;
use App\Enums\CompRequestStatus;
use App\Enums\EntryCoverageSource;
use App\Enums\Role;
use App\Filament\Admin\Pages\ShowrunnerCompRequests;
use App\Filament\Admin\Resources\Events\Pages\EditEvent;
use App\Filament\Admin\Resources\Events\RelationManagers\CompRequestsRelationManager;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\BanException;
use App\Models\CompReason;
use App\Models\CompRequest;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // PricingService::price() (via CompRequestsRelationManager::approveAction())
    // always resolves the entry target -- present in a real install via
    // AddOnSeeder, seeded directly here for this test's minimal fixture.
    AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
});

function makeShowrunner(?Event $event = null): array
{
    $member = Member::factory()->create();
    $user = User::factory()->create(['role' => Role::Showrunner, 'member_id' => $member->id, 'active' => true]);
    $event ??= Event::factory()->create(['showrunner_id' => $member->id, 'event_date' => today()]);

    return [$user, $member, $event];
}

test('only a showrunner-role user can access the comp requests page', function () {
    $door = User::factory()->create(['role' => Role::Door, 'active' => true]);
    $manager = User::factory()->create(['role' => Role::Manager, 'active' => true]);
    [$showrunnerUser] = makeShowrunner();

    $this->actingAs($door);
    expect(ShowrunnerCompRequests::canAccess())->toBeFalse();

    $this->actingAs($manager);
    expect(ShowrunnerCompRequests::canAccess())->toBeFalse();

    $this->actingAs($showrunnerUser);
    expect(ShowrunnerCompRequests::canAccess())->toBeTrue();
});

test('disabling showrunner_comp_requests_enabled blocks the page even for a showrunner, and re-enabling restores it', function () {
    [$showrunnerUser] = makeShowrunner();

    MembershipSetting::current()->update(['showrunner_comp_requests_enabled' => false]);
    $this->actingAs($showrunnerUser);
    expect(ShowrunnerCompRequests::canAccess())->toBeFalse();

    MembershipSetting::current()->update(['showrunner_comp_requests_enabled' => true]);
    expect(ShowrunnerCompRequests::canAccess())->toBeTrue();
});

test('disabling showrunner_comp_requests_enabled blocks a manager\'s CompRequestPolicy access to the approval queue', function () {
    $manager = User::factory()->create(['role' => Role::Manager, 'active' => true]);

    expect($manager->can('viewAny', CompRequest::class))->toBeTrue();

    MembershipSetting::current()->update(['showrunner_comp_requests_enabled' => false]);
    expect($manager->can('viewAny', CompRequest::class))->toBeFalse();

    MembershipSetting::current()->update(['showrunner_comp_requests_enabled' => true]);
    expect($manager->can('viewAny', CompRequest::class))->toBeTrue();
});

test('a showrunner only sees events they are assigned to', function () {
    [$user, $member, $ownEvent] = makeShowrunner();
    $otherEvent = Event::factory()->create();

    $this->actingAs($user);

    $query = (new ReflectionClass(ShowrunnerCompRequests::class))->getMethod('eventOptionsQuery');
    $query->setAccessible(true);
    $ids = $query->invoke(null)->pluck('id');

    expect($ids)->toContain($ownEvent->id)
        ->and($ids)->not->toContain($otherEvent->id);
});

test('a showrunner does not see a past event they are assigned to, but does see today and future ones', function () {
    $member = Member::factory()->create();
    $user = User::factory()->create(['role' => Role::Showrunner, 'member_id' => $member->id, 'active' => true]);
    $pastEvent = Event::factory()->create(['showrunner_id' => $member->id, 'event_date' => today()->subDay(), 'ends_at' => today()->subDay()->setTime(23, 0)]);
    $todayEvent = Event::factory()->create(['showrunner_id' => $member->id, 'event_date' => today()]);
    $futureEvent = Event::factory()->create(['showrunner_id' => $member->id, 'event_date' => today()->addWeek()]);
    $stillRunningPastMidnight = Event::factory()->create([
        'showrunner_id' => $member->id,
        'event_date' => today()->subDay(),
        'ends_at' => now()->addHour(),
    ]);

    $this->actingAs($user);

    $query = (new ReflectionClass(ShowrunnerCompRequests::class))->getMethod('eventOptionsQuery');
    $query->setAccessible(true);
    $ids = $query->invoke(null)->pluck('id');

    expect($ids)->not->toContain($pastEvent->id)
        ->and($ids)->toContain($todayEvent->id)
        ->and($ids)->toContain($futureEvent->id)
        ->and($ids)->toContain($stillRunningPastMidnight->id);
});

test('forcing the event id to a past event resolves to no selected event, even though the showrunner is assigned to it', function () {
    $member = Member::factory()->create();
    $user = User::factory()->create(['role' => Role::Showrunner, 'member_id' => $member->id, 'active' => true]);
    $pastEvent = Event::factory()->create(['showrunner_id' => $member->id, 'event_date' => today()->subWeek(), 'ends_at' => today()->subWeek()->setTime(23, 0)]);

    $this->actingAs($user);

    $component = Livewire::test(ShowrunnerCompRequests::class)
        ->set('data.event_id', $pastEvent->id);

    expect($component->instance()->getSelectedEvent())->toBeNull();
});

test('forcing the event id to an unassigned event resolves to no selected event', function () {
    [$user] = makeShowrunner();
    $otherEvent = Event::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test(ShowrunnerCompRequests::class)
        ->set('data.event_id', $otherEvent->id);

    expect($component->instance()->getSelectedEvent())->toBeNull();
});

test('a showrunner can submit a comp request, which stays pending and creates no attendance row', function () {
    [$user, , $event] = makeShowrunner();
    $target = Member::factory()->create();
    $reason = CompReason::factory()->create();

    $this->actingAs($user);

    Livewire::test(ShowrunnerCompRequests::class)
        ->fillForm(['event_id' => $event->id])
        ->callAction('requestComp', data: [
            'member_id' => $target->id,
            'comp_reason_id' => $reason->id,
            'notes' => 'Worked the door all night',
        ])
        ->assertHasNoActionErrors();

    $request = CompRequest::where('event_id', $event->id)->where('member_id', $target->id)->firstOrFail();
    expect($request->status)->toBe(CompRequestStatus::Pending)
        ->and($request->requested_by)->toBe($user->id)
        ->and($request->comp_reason_id)->toBe($reason->id);

    expect(Attendance::where('event_id', $event->id)->where('member_id', $target->id)->exists())->toBeFalse();
});

test('a showrunner can submit a comp request with a freeform reason instead of picking one from the list', function () {
    [$user, , $event] = makeShowrunner();
    $target = Member::factory()->create();

    $this->actingAs($user);

    Livewire::test(ShowrunnerCompRequests::class)
        ->fillForm(['event_id' => $event->id])
        ->callAction('requestComp', data: [
            'member_id' => $target->id,
            'other_reason' => true,
            'requested_reason_text' => 'Ran the coat check all night',
        ])
        ->assertHasNoActionErrors();

    $request = CompRequest::where('event_id', $event->id)->where('member_id', $target->id)->firstOrFail();
    expect($request->comp_reason_id)->toBeNull()
        ->and($request->requested_reason_text)->toBe('Ran the coat check all night')
        ->and($request->status)->toBe(CompRequestStatus::Pending);
});

test('requested_reason_text is required once "reason isn\'t in the list" is checked', function () {
    [$user, , $event] = makeShowrunner();
    $target = Member::factory()->create();

    $this->actingAs($user);

    Livewire::test(ShowrunnerCompRequests::class)
        ->fillForm(['event_id' => $event->id])
        ->callAction('requestComp', data: [
            'member_id' => $target->id,
            'other_reason' => true,
        ])
        ->assertHasActionErrors(['requested_reason_text']);

    expect(CompRequest::count())->toBe(0);
});

test('the request-comp action is unreachable for an event a showrunner is not assigned to, even by forcing the event id', function () {
    [$user] = makeShowrunner();
    $otherEvent = Event::factory()->create();

    $this->actingAs($user);

    // getSelectedEvent() re-derives from the scoped eventOptionsQuery(), so
    // forcing the Livewire state to a foreign event id still resolves to no
    // selected event — the action's visible() (and Filament's own
    // assertActionVisible-backed callAction) can never reach it.
    Livewire::test(ShowrunnerCompRequests::class)
        ->set('data.event_id', $otherEvent->id)
        ->assertActionHidden('requestComp');

    expect(CompRequest::count())->toBe(0);
});

test('a showrunner cannot request a comp for a member who already has an attendance row for the event', function () {
    [$user, , $event] = makeShowrunner();
    $target = Member::factory()->create();
    $reason = CompReason::factory()->create();
    Attendance::factory()->for($event)->for($target)->create(['checked_in_at' => null]);

    $this->actingAs($user);

    Livewire::test(ShowrunnerCompRequests::class)
        ->fillForm(['event_id' => $event->id])
        ->callAction('requestComp', data: [
            'member_id' => $target->id,
            'comp_reason_id' => $reason->id,
        ])
        ->assertHasActionErrors();

    expect(CompRequest::count())->toBe(0);
});

test('a showrunner cannot submit a second pending request for the same member and event', function () {
    [$user, , $event] = makeShowrunner();
    $target = Member::factory()->create();
    $reason = CompReason::factory()->create();
    CompRequest::factory()->for($event)->create(['member_id' => $target->id, 'status' => CompRequestStatus::Pending]);

    $this->actingAs($user);

    Livewire::test(ShowrunnerCompRequests::class)
        ->fillForm(['event_id' => $event->id])
        ->callAction('requestComp', data: [
            'member_id' => $target->id,
            'comp_reason_id' => $reason->id,
        ])
        ->assertHasActionErrors();

    expect(CompRequest::where('event_id', $event->id)->where('member_id', $target->id)->count())->toBe(1);
});

test('a showrunner cannot request a comp for a banned member with no exception for the event', function () {
    [$user, , $event] = makeShowrunner();
    $target = Member::factory()->create(['is_banned' => true]);
    $reason = CompReason::factory()->create();

    $this->actingAs($user);

    Livewire::test(ShowrunnerCompRequests::class)
        ->fillForm(['event_id' => $event->id])
        ->callAction('requestComp', data: [
            'member_id' => $target->id,
            'comp_reason_id' => $reason->id,
        ])
        ->assertHasActionErrors();

    expect(CompRequest::count())->toBe(0);
});

test('a showrunner can request a comp for a banned member who has a granted exception for that event', function () {
    [$user, , $event] = makeShowrunner();
    $target = Member::factory()->create(['is_banned' => true]);
    $reason = CompReason::factory()->create();
    BanException::factory()->create(['member_id' => $target->id, 'event_id' => $event->id]);

    $this->actingAs($user);

    Livewire::test(ShowrunnerCompRequests::class)
        ->fillForm(['event_id' => $event->id])
        ->callAction('requestComp', data: [
            'member_id' => $target->id,
            'comp_reason_id' => $reason->id,
        ])
        ->assertHasNoActionErrors();

    expect(CompRequest::where('event_id', $event->id)->where('member_id', $target->id)->exists())->toBeTrue();
});

test('a showrunner can request a comp for a member whose suspension has already expired, no exception needed', function () {
    [$user, , $event] = makeShowrunner();
    $target = Member::factory()->create(['is_banned' => true, 'banned_until' => now()->subMonth()->toDateString()]);
    $reason = CompReason::factory()->create();

    $this->actingAs($user);

    Livewire::test(ShowrunnerCompRequests::class)
        ->fillForm(['event_id' => $event->id])
        ->callAction('requestComp', data: [
            'member_id' => $target->id,
            'comp_reason_id' => $reason->id,
        ])
        ->assertHasNoActionErrors();

    expect(CompRequest::where('event_id', $event->id)->where('member_id', $target->id)->exists())->toBeTrue();
});

test('submitting a comp request sends a database notification to every active admin+ user, and no one else', function () {
    [$user, , $event] = makeShowrunner();
    $target = Member::factory()->create();
    $reason = CompReason::factory()->create();

    $admin = User::factory()->create(['role' => Role::Admin, 'active' => true]);
    $owner = User::factory()->create(['role' => Role::Owner, 'active' => true]);
    $manager = User::factory()->create(['role' => Role::Manager, 'active' => true]);
    $inactiveAdmin = User::factory()->create(['role' => Role::Admin, 'active' => false]);

    $this->actingAs($user);

    Livewire::test(ShowrunnerCompRequests::class)
        ->fillForm(['event_id' => $event->id])
        ->callAction('requestComp', data: [
            'member_id' => $target->id,
            'comp_reason_id' => $reason->id,
        ])
        ->assertHasNoActionErrors();

    expect($admin->notifications()->count())->toBe(1)
        ->and($owner->notifications()->count())->toBe(1)
        ->and($manager->notifications()->count())->toBe(0)
        ->and($inactiveAdmin->notifications()->count())->toBe(0);
});

test('the comp-request notification is delivered immediately even under the database queue driver, not left queued', function () {
    // phpunit.xml forces QUEUE_CONNECTION=sync, which would mask a
    // regression to Notification::sendToDatabase() — Filament's
    // DatabaseNotification implements ShouldQueue, and the sync driver runs
    // a queued job inline regardless, so the notification would still land
    // under the default test config even if it were wrongly queued. Forcing
    // the database driver here reproduces this app's real .env (no queue
    // worker beyond sync — see the commit history) and would have caught the bug a
    // live migrate:fresh --seed smoke test found: the notification sitting
    // unprocessed in `jobs` forever instead of reaching Admin/Owner.
    config(['queue.default' => 'database']);

    [$user, , $event] = makeShowrunner();
    $target = Member::factory()->create();
    $reason = CompReason::factory()->create();
    $admin = User::factory()->create(['role' => Role::Admin, 'active' => true]);

    $this->actingAs($user);

    Livewire::test(ShowrunnerCompRequests::class)
        ->fillForm(['event_id' => $event->id])
        ->callAction('requestComp', data: [
            'member_id' => $target->id,
            'comp_reason_id' => $reason->id,
        ])
        ->assertHasNoActionErrors();

    expect($admin->notifications()->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('a manager can view the comp request queue but cannot approve or reject', function () {
    $manager = User::factory()->create(['role' => Role::Manager, 'active' => true]);
    $event = Event::factory()->create();
    $request = CompRequest::factory()->for($event)->create(['status' => CompRequestStatus::Pending]);

    $this->actingAs($manager);

    Livewire::test(CompRequestsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->assertCanSeeTableRecords([$request])
        ->assertTableActionHidden('approve', $request)
        ->assertTableActionHidden('reject', $request);
});

test('an admin approving a comp request comps the entry via applyEventComp, leaves pool untouched, and marks it approved', function () {
    AddOn::create(['name' => AddOn::POOL_NAME, 'subscribable' => true, 'priced_per_event' => true]);

    $admin = User::factory()->create(['role' => Role::Admin, 'active' => true]);
    $event = Event::factory()->create(['entry_fee' => 40, 'pool_fee' => 5]);
    $target = Member::factory()->create();
    $reason = CompReason::factory()->create();
    $request = CompRequest::factory()->for($event)->create(['member_id' => $target->id, 'comp_reason_id' => $reason->id, 'status' => CompRequestStatus::Pending]);

    $this->actingAs($admin);

    Livewire::test(CompRequestsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->callTableAction('approve', $request)
        ->assertHasNoTableActionErrors();

    $request->refresh();
    expect($request->status)->toBe(CompRequestStatus::Approved)
        ->and($request->reviewed_by)->toBe($admin->id)
        ->and($request->reviewed_at)->not->toBeNull()
        ->and($request->attendance_id)->not->toBeNull();

    $attendance = Attendance::where('event_id', $event->id)->where('member_id', $target->id)->firstOrFail();
    expect($attendance->checked_in_at)->toBeNull()
        ->and($attendance->entry_covered_by)->toBe(EntryCoverageSource::EventComp)
        ->and($attendance->entry_coverage)->toEqual(40)
        ->and($attendance->comp_reason_id)->toBe($reason->id)
        ->and($attendance->amount_paid)->toEqual(5); // pool fee still owed — entry only
});

test('the reason column shows the freeform text for an unresolved request', function () {
    $admin = User::factory()->create(['role' => Role::Admin, 'active' => true]);
    $event = Event::factory()->create();
    CompRequest::factory()->for($event)->create([
        'comp_reason_id' => null,
        'requested_reason_text' => 'Ran the coat check all night',
        'status' => CompRequestStatus::Pending,
    ]);

    $this->actingAs($admin);

    Livewire::test(CompRequestsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->assertSee('Ran the coat check all night (freeform)');
});

test('an admin must resolve a freeform comp request to a real reason before it can be approved', function () {
    $admin = User::factory()->create(['role' => Role::Admin, 'active' => true]);
    $event = Event::factory()->create();
    $target = Member::factory()->create();
    $request = CompRequest::factory()->for($event)->create([
        'member_id' => $target->id,
        'comp_reason_id' => null,
        'requested_reason_text' => 'Ran the coat check all night',
        'status' => CompRequestStatus::Pending,
    ]);

    $this->actingAs($admin);

    Livewire::test(CompRequestsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->callTableAction('approve', $request, data: [])
        ->assertHasTableActionErrors(['comp_reason_id']);

    expect($request->refresh()->status)->toBe(CompRequestStatus::Pending)
        ->and(Attendance::where('event_id', $event->id)->where('member_id', $target->id)->exists())->toBeFalse();
});

test('an admin can approve a freeform comp request by resolving it to an existing reason, and it is saved on both the request and the attendance', function () {
    $admin = User::factory()->create(['role' => Role::Admin, 'active' => true]);
    $event = Event::factory()->create(['entry_fee' => 40, 'pool_fee' => 0]);
    $target = Member::factory()->create();
    $reason = CompReason::factory()->create(['name' => 'Coat Check']);
    $request = CompRequest::factory()->for($event)->create([
        'member_id' => $target->id,
        'comp_reason_id' => null,
        'requested_reason_text' => 'Ran the coat check all night',
        'status' => CompRequestStatus::Pending,
    ]);

    $this->actingAs($admin);

    Livewire::test(CompRequestsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->callTableAction('approve', $request, data: ['comp_reason_id' => $reason->id])
        ->assertHasNoTableActionErrors();

    expect($request->refresh()->comp_reason_id)->toBe($reason->id)
        ->and($request->status)->toBe(CompRequestStatus::Approved);

    $attendance = Attendance::where('event_id', $event->id)->where('member_id', $target->id)->firstOrFail();
    expect($attendance->comp_reason_id)->toBe($reason->id)
        ->and($attendance->entry_covered_by)->toBe(EntryCoverageSource::EventComp);
});

test('approving a comp request is rejected once the building is at capacity, leaving it pending', function () {
    MembershipSetting::current()->update(['venue_capacity' => 1]);
    $admin = User::factory()->create(['role' => Role::Admin, 'active' => true]);
    $event = Event::factory()->create(['event_date' => today()->toDateString()]);
    Attendance::factory()->for($event)->create(['checked_in_at' => now()]);

    $target = Member::factory()->create();
    $request = CompRequest::factory()->for($event)->create(['member_id' => $target->id, 'status' => CompRequestStatus::Pending]);

    $this->actingAs($admin);

    Livewire::test(CompRequestsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->callTableAction('approve', $request);

    expect($request->refresh()->status)->toBe(CompRequestStatus::Pending)
        ->and(Attendance::where('event_id', $event->id)->where('member_id', $target->id)->exists())->toBeFalse();
});

test('the approve action is hidden and blocked server-side for a request against a member who was banned after submission, with no exception for the event', function () {
    $admin = User::factory()->create(['role' => Role::Admin, 'active' => true]);
    $event = Event::factory()->create();
    $target = Member::factory()->create(['is_banned' => true]);
    $request = CompRequest::factory()->for($event)->create(['member_id' => $target->id, 'status' => CompRequestStatus::Pending]);

    $this->actingAs($admin);

    Livewire::test(CompRequestsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->assertTableActionHidden('approve', $request);

    expect($request->refresh()->status)->toBe(CompRequestStatus::Pending)
        ->and(Attendance::where('event_id', $event->id)->where('member_id', $target->id)->exists())->toBeFalse();
});

test('an admin can still approve a comp request for a banned member who has a granted exception for that event', function () {
    $admin = User::factory()->create(['role' => Role::Admin, 'active' => true]);
    $event = Event::factory()->create();
    $target = Member::factory()->create(['is_banned' => true]);
    BanException::factory()->create(['member_id' => $target->id, 'event_id' => $event->id]);
    $request = CompRequest::factory()->for($event)->create(['member_id' => $target->id, 'status' => CompRequestStatus::Pending]);

    $this->actingAs($admin);

    Livewire::test(CompRequestsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->callTableAction('approve', $request)
        ->assertHasNoTableActionErrors();

    expect($request->refresh()->status)->toBe(CompRequestStatus::Approved);
});

test('an admin rejecting a comp request marks it rejected and creates no attendance row', function () {
    $admin = User::factory()->create(['role' => Role::Admin, 'active' => true]);
    $event = Event::factory()->create();
    $target = Member::factory()->create();
    $request = CompRequest::factory()->for($event)->create(['member_id' => $target->id, 'status' => CompRequestStatus::Pending]);

    $this->actingAs($admin);

    Livewire::test(CompRequestsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->callTableAction('reject', $request, data: ['review_notes' => 'Not on the volunteer list'])
        ->assertHasNoTableActionErrors();

    $request->refresh();
    expect($request->status)->toBe(CompRequestStatus::Rejected)
        ->and($request->reviewed_by)->toBe($admin->id)
        ->and($request->review_notes)->toBe('Not on the volunteer list')
        ->and($request->attendance_id)->toBeNull();

    expect(Attendance::where('event_id', $event->id)->where('member_id', $target->id)->exists())->toBeFalse();
});

test('the check-in page is inaccessible to a showrunner-role user', function () {
    [$user] = makeShowrunner();

    $this->actingAs($user)->get('/admin/check-in')->assertForbidden();
});
