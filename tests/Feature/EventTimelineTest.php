<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\EventTimeline;
use App\Filament\Admin\Resources\EventTimelineCategories\Pages\ListEventTimelineCategories;
use App\Models\Event;
use App\Models\EventTimelineCategory;
use App\Models\EventTimelineItem;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\User;
use App\Services\EventTimelineService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    MembershipSetting::current()->update(['event_timelines_enabled' => true]);
});

function timelineEvent(array $attributes = []): Event
{
    $date = today()->addDay()->toDateString();

    return Event::factory()->create($attributes + [
        'event_date' => $date,
        'starts_at' => "{$date} 20:00:00",
        'ends_at' => today()->addDays(2)->toDateString().' 02:00:00',
    ]);
}

function timelineShowrunner(Event $event): User
{
    $member = Member::factory()->create();
    $event->update(['showrunner_id' => $member->id]);

    return User::factory()->create(['role' => Role::Showrunner, 'member_id' => $member->id, 'active' => true]);
}

function timelineUser(Role $role): User
{
    return User::factory()->create(['role' => $role, 'active' => true]);
}

function itemData(Event $event, array $overrides = []): array
{
    return $overrides + [
        'event_timeline_category_id' => EventTimelineCategory::where('name', 'Public')->value('id'),
        'title' => 'Doors open',
        'starts_at' => $event->starts_at->format('Y-m-d H:i:s'),
        'ends_at' => $event->starts_at->copy()->addMinutes(30)->format('Y-m-d H:i:s'),
    ];
}

test('the migration seeds a Public and an Event staff category, and the flag defaults off', function () {
    expect(EventTimelineCategory::where('name', 'Public')->value('is_public'))->toBeTrue()
        ->and(EventTimelineCategory::where('name', 'Event staff')->first()->min_role)->toBe(Role::Volunteer);

    MembershipSetting::query()->delete();
    expect(MembershipSetting::current()->event_timelines_enabled)->toBeFalse();
});

test('the event\'s showrunner edits its timeline while it is current or upcoming, not another\'s or a past one', function () {
    $event = timelineEvent();
    $showrunner = timelineShowrunner($event);

    expect(Gate::forUser($showrunner)->allows('edit-event-timeline', $event))->toBeTrue()
        ->and(Gate::forUser($showrunner)->allows('edit-event-timeline', timelineEvent()))->toBeFalse();

    $past = Event::factory()->create(['event_date' => today()->subWeek(), 'starts_at' => today()->subWeek()->setTime(20, 0), 'ends_at' => today()->subWeek()->setTime(23, 0), 'showrunner_id' => $showrunner->member_id]);
    expect(Gate::forUser($showrunner)->allows('edit-event-timeline', $past))->toBeFalse();
});

test('Admin+ edits any event\'s timeline, past ones too; Manager and Door cannot', function () {
    $event = timelineEvent();
    $past = Event::factory()->create(['event_date' => today()->subWeek(), 'starts_at' => today()->subWeek()->setTime(20, 0), 'ends_at' => today()->subWeek()->setTime(23, 0)]);

    foreach ([Role::Admin, Role::Owner] as $role) {
        expect(Gate::forUser(timelineUser($role))->allows('edit-event-timeline', $event))->toBeTrue()
            ->and(Gate::forUser(timelineUser($role))->allows('edit-event-timeline', $past))->toBeTrue();
    }

    foreach ([Role::Manager, Role::Door, Role::Volunteer] as $role) {
        expect(Gate::forUser(timelineUser($role))->allows('edit-event-timeline', $event))->toBeFalse();
    }
});

test('with the flag off nobody edits a timeline or reaches the page', function () {
    MembershipSetting::current()->update(['event_timelines_enabled' => false]);
    $admin = timelineUser(Role::Admin);

    expect(Gate::forUser($admin)->allows('edit-event-timeline', timelineEvent()))->toBeFalse();

    $this->actingAs($admin);
    expect(EventTimeline::canAccess())->toBeFalse();
});

test('the page is for Admin+ and showrunners only in this slice', function () {
    $event = timelineEvent();

    $this->actingAs(timelineShowrunner($event));
    expect(EventTimeline::canAccess())->toBeTrue();

    $this->actingAs(timelineUser(Role::Admin));
    expect(EventTimeline::canAccess())->toBeTrue();

    $this->actingAs(timelineUser(Role::Manager));
    expect(EventTimeline::canAccess())->toBeFalse();
});

test('a Manager calling the service directly is refused and nothing is written', function () {
    $event = timelineEvent();

    expect(fn () => app(EventTimelineService::class)->save(timelineUser(Role::Manager), $event, itemData($event)))
        ->toThrow(HttpException::class);

    expect(EventTimelineItem::count())->toBe(0);
});

test('an item must sit inside the event\'s start and end and end after it starts', function (Closure $times) {
    $event = timelineEvent();
    $admin = timelineUser(Role::Admin);

    expect(fn () => app(EventTimelineService::class)->save($admin, $event, itemData($event, $times($event))))
        ->toThrow(ValidationException::class);

    expect(EventTimelineItem::count())->toBe(0);
})->with([
    'starts before the event' => [fn (Event $event) => ['starts_at' => $event->starts_at->copy()->subMinute()->format('Y-m-d H:i:s')]],
    'ends after the event' => [fn (Event $event) => ['ends_at' => $event->ends_at->copy()->addMinute()->format('Y-m-d H:i:s')]],
    'ends before it starts' => [fn (Event $event) => ['ends_at' => $event->starts_at->format('Y-m-d H:i:s')]],
]);

test('an event with no start and end can\'t have a timeline', function () {
    $event = timelineEvent();
    $data = itemData($event);
    $event->update(['starts_at' => null, 'ends_at' => null]);

    expect(fn () => app(EventTimelineService::class)->save(timelineUser(Role::Admin), $event->fresh(), $data))
        ->toThrow(ValidationException::class);
});

test('an item across midnight is fine, and a moment has no end', function () {
    $event = timelineEvent();
    $admin = timelineUser(Role::Admin);
    $service = app(EventTimelineService::class);

    $service->save($admin, $event, itemData($event, [
        'starts_at' => $event->ends_at->copy()->subHours(2)->format('Y-m-d H:i:s'),
        'ends_at' => $event->ends_at->format('Y-m-d H:i:s'),
    ]));
    $service->save($admin, $event, itemData($event, ['title' => 'Raffle draw', 'ends_at' => null]));

    expect(EventTimelineItem::count())->toBe(2)
        ->and(EventTimelineItem::where('title', 'Raffle draw')->first()->ends_at)->toBeNull();
});

test('who sees which category', function () {
    $event = timelineEvent();
    $public = EventTimelineCategory::where('name', 'Public')->first();
    $staff = EventTimelineCategory::where('name', 'Event staff')->first();
    $managers = EventTimelineCategory::factory()->forRole(Role::Manager)->create();
    $leadsOnly = EventTimelineCategory::factory()->forRole(null)->create();

    foreach ([$public, $staff, $managers, $leadsOnly] as $category) {
        EventTimelineItem::factory()->create(['event_id' => $event->id, 'event_timeline_category_id' => $category->id, 'starts_at' => $event->starts_at]);
    }

    $seen = fn (?User $user): array => app(EventTimelineService::class)->itemsFor($event, $user)
        ->pluck('event_timeline_category_id')->sort()->values()->all();
    $ids = fn (EventTimelineCategory ...$categories): array => collect($categories)->pluck('id')->sort()->values()->all();

    expect($seen(null))->toBe($ids($public))
        ->and($seen(timelineUser(Role::Volunteer)))->toBe($ids($public, $staff))
        ->and($seen(timelineUser(Role::Manager)))->toBe($ids($public, $staff, $managers))
        ->and($seen(timelineUser(Role::Admin)))->toBe($ids($public, $staff, $managers, $leadsOnly))
        ->and($seen(timelineShowrunner($event)))->toBe($ids($public, $staff, $managers, $leadsOnly));
});

test('the layout puts overlapping items in side-by-side lanes and places a cross-midnight item by the event\'s length', function () {
    $event = timelineEvent(); // 20:00 to 02:00, six hours
    $category = EventTimelineCategory::first();
    $make = fn (string $title, Carbon $from, ?Carbon $to) => EventTimelineItem::factory()->create([
        'event_id' => $event->id, 'event_timeline_category_id' => $category->id, 'title' => $title, 'starts_at' => $from, 'ends_at' => $to,
    ]);

    $start = $event->starts_at;
    $make('A', $start->copy(), $start->copy()->addHour());
    $make('B', $start->copy()->addMinutes(30), $start->copy()->addMinutes(90));
    $make('C', $start->copy()->addHours(3), $start->copy()->addHours(6)); // 23:00 to 02:00

    $layout = app(EventTimelineService::class)->layout($event, $event->timelineItems()->with('category')->get());
    $blocks = collect($layout['blocks'])->keyBy(fn (array $block) => $block['item']->title);

    expect($blocks['A']['lanes'])->toBe(2)->and($blocks['A']['lane'])->toBe(0)
        ->and($blocks['B']['lanes'])->toBe(2)->and($blocks['B']['lane'])->toBe(1)
        ->and($blocks['C']['lanes'])->toBe(1)
        ->and($blocks['C']['top'])->toEqual(50.0)
        ->and($blocks['C']['height'])->toEqual(50.0)
        ->and($layout['slotCount'])->toBe(24)
        ->and($layout['hours'])->toHaveCount(7);
});

test('a showrunner adds, changes and deletes items from the page', function () {
    $event = timelineEvent();
    $this->actingAs(timelineShowrunner($event));
    $staff = EventTimelineCategory::where('name', 'Event staff')->value('id');

    Livewire::test(EventTimeline::class)
        ->assertSet('data.event_id', $event->id)
        ->callAction('addItem', data: ['title' => 'Set up the bar', 'event_timeline_category_id' => $staff], arguments: ['at' => $event->starts_at->copy()->addHour()->format('Y-m-d H:i:s')])
        ->assertHasNoActionErrors();

    $item = EventTimelineItem::sole();
    expect($item->title)->toBe('Set up the bar')
        ->and($item->starts_at->equalTo($event->starts_at->copy()->addHour()))->toBeTrue()
        ->and($item->ends_at->equalTo($event->starts_at->copy()->addMinutes(90)))->toBeTrue();

    Livewire::test(EventTimeline::class)
        ->callAction('editItem', data: ['title' => 'Stock the bar'], arguments: ['item' => $item->id])
        ->assertHasNoActionErrors();
    expect($item->fresh()->title)->toBe('Stock the bar');

    Livewire::test(EventTimeline::class)
        ->callAction([['name' => 'editItem', 'arguments' => ['item' => $item->id]], 'deleteItem']);
    expect(EventTimelineItem::count())->toBe(0);
});

test('the page refuses an event the user may not edit, even with a forged id', function () {
    $own = timelineEvent();
    $other = timelineEvent();
    $this->actingAs(timelineShowrunner($own));

    Livewire::test(EventTimeline::class)
        ->set('data.event_id', $other->id)
        ->assertActionHidden('addItem');

    expect(EventTimelineItem::count())->toBe(0);
});

test('the page rejects an item outside the event\'s times', function () {
    $event = timelineEvent();
    $this->actingAs(timelineUser(Role::Admin));

    Livewire::test(EventTimeline::class)
        ->set('data.event_id', $event->id)
        ->callAction('addItem', data: [
            'title' => 'Too early',
            'starts_at' => $event->starts_at->copy()->subHour()->format('Y-m-d H:i:s'),
            'ends_at' => null,
        ])
        ->assertHasActionErrors(['starts_at']);

    expect(EventTimelineItem::count())->toBe(0);
});

test('a category in use can\'t be deleted, and deleting an event removes its timeline', function () {
    $event = timelineEvent();
    $category = EventTimelineCategory::factory()->create();
    EventTimelineItem::factory()->create(['event_id' => $event->id, 'event_timeline_category_id' => $category->id, 'starts_at' => $event->starts_at]);

    expect(timelineUser(Role::Admin)->can('delete', $category))->toBeFalse()
        ->and(timelineUser(Role::Admin)->can('delete', EventTimelineCategory::factory()->create()))->toBeTrue()
        ->and(timelineUser(Role::Manager)->can('viewAny', EventTimelineCategory::class))->toBeFalse();

    expect(fn () => $category->delete())->toThrow(QueryException::class);

    $event->delete();
    expect(EventTimelineItem::count())->toBe(0);
});

test('Admin reaches the categories list', function () {
    $this->actingAs(timelineUser(Role::Admin));

    Livewire::test(ListEventTimelineCategories::class)
        ->assertCanSeeTableRecords(EventTimelineCategory::all());
});
