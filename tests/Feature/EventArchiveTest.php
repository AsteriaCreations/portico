<?php

use App\Enums\AddOnKind;
use App\Enums\CompRequestStatus;
use App\Enums\Role;
use App\Exceptions\CheckInRefused;
use App\Filament\Admin\Pages\CheckIn;
use App\Filament\Admin\Resources\Events\Pages\EditEvent;
use App\Filament\Admin\Resources\Events\Pages\ListEvents;
use App\Filament\Admin\Resources\Events\RelationManagers\AttendanceRelationManager;
use App\Filament\Admin\Resources\Events\RelationManagers\CompRequestsRelationManager;
use App\Filament\Admin\Resources\Events\RelationManagers\PrepayListRelationManager;
use App\Models\AddOn;
use App\Models\AddOnDayPass;
use App\Models\Attendance;
use App\Models\BanException;
use App\Models\Category;
use App\Models\CompRequest;
use App\Models\Event;
use App\Models\Member;
use App\Models\Plan;
use App\Models\User;
use App\Services\CheckInRequest;
use App\Services\CheckInService;
use App\Services\PrepayListImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['active' => true, 'role' => Role::Admin]);
    $this->actingAs($this->admin);
});

function pastEvent(array $overrides = []): Event
{
    $date = today()->subWeek();

    return Event::factory()->create([
        'event_date' => $date->toDateString(),
        'starts_at' => $date->copy()->setTime(20, 0),
        'ends_at' => $date->copy()->setTime(23, 0),
        ...$overrides,
    ]);
}

function upcomingEvent(array $overrides = []): Event
{
    $date = today()->addWeek();

    return Event::factory()->create([
        'event_date' => $date->toDateString(),
        'starts_at' => $date->copy()->setTime(20, 0),
        'ends_at' => $date->copy()->setTime(23, 0),
        ...$overrides,
    ]);
}

function tonightEvent(array $overrides = []): Event
{
    return Event::factory()->create([
        'event_date' => today()->toDateString(),
        'starts_at' => today()->setTime(0, 0),
        'ends_at' => today()->setTime(23, 59),
        ...$overrides,
    ]);
}

test('an admin can archive an ended event, recording who and when, and unarchive it again', function () {
    $event = pastEvent();
    Attendance::factory()->for($event)->create(['checked_in_at' => now()->subWeek()]);

    Livewire::test(ListEvents::class)
        ->callTableAction('archive', $event)
        ->assertHasNoTableActionErrors();

    $event->refresh();
    expect($event->isArchived())->toBeTrue()
        ->and($event->archived_by)->toBe($this->admin->id);

    Livewire::test(ListEvents::class)
        ->filterTable('archived', true)
        ->callTableAction('unarchive', $event)
        ->assertHasNoTableActionErrors();

    $event->refresh();
    expect($event->isArchived())->toBeFalse()
        ->and($event->archived_by)->toBeNull();
});

test('an upcoming event can be archived only while nobody is on it', function () {
    $empty = upcomingEvent();
    $prepaid = upcomingEvent();
    Attendance::factory()->for($prepaid)->create(['checked_in_at' => null]);

    expect(Gate::allows('archive', $empty))->toBeTrue()
        ->and(Gate::allows('archive', $prepaid))->toBeFalse();

    Livewire::test(ListEvents::class)
        ->assertTableActionVisible('archive', $empty)
        ->assertTableActionHidden('archive', $prepaid);
});

test('a manager cannot archive or unarchive', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $event = pastEvent();
    $archived = pastEvent();
    $archived->archive($this->admin);

    expect(Gate::forUser($manager)->allows('archive', $event))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('restore', $archived))->toBeFalse();
});

test('the events list hides archived events by default and shows them under the Archived filter', function () {
    $active = pastEvent();
    $archived = pastEvent();
    $archived->archive($this->admin);

    Livewire::test(ListEvents::class)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$archived])
        ->filterTable('archived', true)
        ->assertCanSeeTableRecords([$archived])
        ->assertCanNotSeeTableRecords([$active]);
});

test('an archived event is never current, even tonight, and drops out of every desk-facing query', function () {
    $tonight = tonightEvent(['door_prepay_enabled' => true]);
    $upcoming = upcomingEvent(['door_prepay_enabled' => true]);
    $tonight->archive($this->admin);
    $upcoming->archive($this->admin);

    $eventSelectQuery = (new ReflectionClass(CheckIn::class))->getMethod('eventSelectQuery');
    $eventSelectQuery->setAccessible(true);

    expect($tonight->isCurrentlyActive())->toBeFalse()
        ->and(Event::currentQuery()->pluck('id'))->not->toContain($tonight->id)
        ->and(Event::currentOrFutureQuery()->pluck('id'))->not->toContain($upcoming->id)
        ->and($eventSelectQuery->invoke(null)->pluck('id'))->not->toContain($tonight->id)
        ->not->toContain($upcoming->id);

    $tonight->unarchive();

    expect(Event::currentQuery()->pluck('id'))->toContain($tonight->id)
        ->and($eventSelectQuery->invoke(null)->pluck('id'))->toContain($tonight->id);
});

test('CheckInService refuses an event archived after the desk loaded it, recording nothing', function () {
    $category = Category::factory()->create(['name' => 'Irregular', 'is_comped' => false]);
    $entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    Plan::create(['add_on_id' => $entry->id, 'price' => 60, 'credit' => 25, 'effective_from' => '2026-01-01']);
    $member = Member::factory()->create(['category_id' => $category->id, 'dob' => '1990-01-01', 'is_banned' => false, 'on_watchlist' => false]);
    $event = tonightEvent(['entry_fee' => 20]);

    // The desk still holds the unarchived instance; only the database knows.
    Event::whereKey($event->id)->update(['archived_at' => now(), 'archived_by' => $this->admin->id]);

    expect(fn () => app(CheckInService::class)->record($member, $event, $this->admin, new CheckInRequest, null))
        ->toThrow(CheckInRefused::class);
    expect(Attendance::where('event_id', $event->id)->exists())->toBeFalse();
});

test('a forged check-in onto an archived door-prepay event is rejected at the desk', function () {
    $category = Category::factory()->create(['name' => 'Irregular', 'is_comped' => false]);
    Category::factory()->create(['name' => 'Prospective', 'is_comped' => false]);
    Category::factory()->create(['name' => 'Guest', 'is_comped' => false]);
    $entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    Plan::create(['add_on_id' => $entry->id, 'price' => 60, 'credit' => 25, 'effective_from' => '2026-01-01']);
    $member = Member::factory()->create([
        'category_id' => $category->id, 'dob' => '1990-01-01', 'is_banned' => false, 'on_watchlist' => false,
        'first_name' => 'Pat', 'last_name' => 'Doe', 'email' => 'pat@example.com',
    ]);
    $event = upcomingEvent(['door_prepay_enabled' => true, 'entry_fee' => 20]);
    $event->archive($this->admin);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: ['checked_in_at' => null]);

    expect(Attendance::where('event_id', $event->id)->exists())->toBeFalse();
});

test("an archived event's tabs are read-only, including comp-request approval and the prepay bulk upload", function () {
    $event = pastEvent();
    $request = CompRequest::factory()->for($event)->create(['status' => CompRequestStatus::Pending]);
    $event->archive($this->admin);

    expect(Livewire::test(AttendanceRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])->instance()->isReadOnly())->toBeTrue();

    Livewire::test(CompRequestsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->assertTableActionHidden('approve', $request)
        ->assertTableActionHidden('reject', $request);

    Livewire::test(PrepayListRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->assertTableActionHidden('bulkUploadPrepay');

    expect(app(PrepayListImporter::class)->import($event, 'unused-path.csv', $this->admin))
        ->toBe(['created' => 0, 'log' => ['event is archived, nothing imported']]);
});

test('an archived event cannot be saved through the edit form', function () {
    $event = pastEvent(['name' => 'Original']);
    $event->archive($this->admin);

    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->assertSee('This event is read-only')
        ->call('save');

    expect($event->refresh()->name)->toBe('Original');
});

test('only an event with nothing recorded against it can be deleted', function (Closure $record) {
    $empty = pastEvent();
    $used = pastEvent();
    $record($used);

    expect(Gate::allows('delete', $empty))->toBeTrue()
        ->and(Gate::allows('delete', $used))->toBeFalse();

    Livewire::test(ListEvents::class)
        ->assertTableActionHidden('delete', $used)
        ->callTableAction('delete', $empty);

    expect(Event::whereKey($empty->id)->exists())->toBeFalse()
        ->and(Event::whereKey($used->id)->exists())->toBeTrue();
})->with([
    'attendance' => [fn (Event $event) => Attendance::factory()->for($event)->create()],
    'comp request' => [fn (Event $event) => CompRequest::factory()->for($event)->create()],
    'day pass' => [fn (Event $event) => AddOnDayPass::factory()->for($event)->create()],
    'ban exception' => [fn (Event $event) => BanException::factory()->for($event)->create()],
]);

test('bulk delete skips events with history instead of failing', function () {
    $empty = pastEvent();
    $used = pastEvent();
    Attendance::factory()->for($used)->create();

    Livewire::test(ListEvents::class)
        ->callTableBulkAction('delete', [$empty, $used]);

    expect(Event::whereKey($empty->id)->exists())->toBeFalse()
        ->and(Event::whereKey($used->id)->exists())->toBeTrue();
});

test('the ended-event summary skips an event archived before it ended, but not one archived after', function () {
    Mail::fake();
    User::factory()->create(['role' => Role::Owner, 'active' => true]);

    $calledOff = pastEvent();
    $calledOff->forceFill(['archived_at' => $calledOff->starts_at->copy()->subDay(), 'archived_by' => $this->admin->id])->save();
    $archivedAfter = pastEvent();
    $archivedAfter->forceFill(['archived_at' => $archivedAfter->ends_at->copy()->addHour(), 'archived_by' => $this->admin->id])->save();

    $this->artisan('events:notify-ended')->assertSuccessful();

    expect($calledOff->fresh()->ended_notification_sent_at)->toBeNull()
        ->and($archivedAfter->fresh()->ended_notification_sent_at)->not->toBeNull();
});
