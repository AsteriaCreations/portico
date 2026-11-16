<?php

use App\Enums\AddOnKind;
use App\Enums\EntryCoverageSource;
use App\Enums\Role;
use App\Filament\Admin\Widgets\EventComparisonWidget;
use App\Filament\Admin\Widgets\EventTypeRollUpWidget;
use App\Filament\Admin\Widgets\EventWeekdayRollUpWidget;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Member;
use App\Models\PrivateRental;
use App\Models\User;
use App\Services\Analytics\EventAnalytics;
use App\Support\AnalyticsPeriod;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // A Wednesday in a November whose first day is a Sunday.
    $this->travelTo('2026-11-18 12:00:00');

    AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    $this->social = EventType::factory()->create(['name' => 'Social', 'sort_order' => 0]);
    $this->class = EventType::factory()->create(['name' => 'Class', 'sort_order' => 1]);
});

/**
 * $count arrived visits to $event, each by a new member, each with $attributes.
 */
function comparisonVisits(Event $event, int $count, array $attributes = []): void
{
    Attendance::factory()->count($count)->for($event)->create([
        'checked_in_at' => $event->event_date->toDateString().' 20:00:00',
        'amount_paid' => 0,
        ...$attributes,
    ]);
}

function comparisonFigures(Event $event, AnalyticsPeriod $period): array
{
    $analytics = app(EventAnalytics::class);

    return $analytics->figures(
        $analytics->comparisonQuery($period)->findOrFail($event->id),
        $analytics->usualHeadcounts($period),
    );
}

test('an event row counts who came, how they got in, and what it took', function () {
    $event = Event::factory()->create(['event_type_id' => $this->social->id, 'event_date' => '2026-11-14']);

    comparisonVisits($event, 2, ['amount_paid' => 20]);
    comparisonVisits($event, 1, ['entry_covered_by' => EntryCoverageSource::RegularSubscription]);
    comparisonVisits($event, 1, ['entry_covered_by' => EntryCoverageSource::EventComp]);
    // Prepaid and never arrived: money for the event, but not a head.
    Attendance::factory()->for($event)->create(['checked_in_at' => null, 'amount_paid' => 10]);

    expect(comparisonFigures($event, AnalyticsPeriod::forPreset('this_month')))->toMatchArray([
        'headcount' => 4,
        'no_shows' => 1,
        'revenue_cents' => 5000,
        'per_head_cents' => 1250,
        'comps' => 1,
        'comp_percent' => 25,
        'subscribers' => 1,
        'subscriber_percent' => 25,
    ]);
});

test('a first-timer at an event is someone on their very first visit', function () {
    $earlier = Event::factory()->create(['event_type_id' => $this->social->id, 'event_date' => '2026-11-07']);
    $event = Event::factory()->create(['event_type_id' => $this->social->id, 'event_date' => '2026-11-14']);

    $returning = Member::factory()->create();
    Attendance::factory()->for($earlier)->for($returning)->create(['checked_in_at' => '2026-11-07 20:00:00']);
    Attendance::factory()->for($event)->for($returning)->create(['checked_in_at' => '2026-11-14 20:00:00']);

    // Here before these records began.
    Attendance::factory()->for($event)->for(Member::factory()->create(['prior_visits' => 4]))->create(['checked_in_at' => '2026-11-14 20:00:00']);

    comparisonVisits($event, 2);

    $month = AnalyticsPeriod::forPreset('this_month');

    expect(comparisonFigures($event, $month)['first_timers'])->toBe(2)
        ->and(comparisonFigures($earlier, $month)['first_timers'])->toBe(1);
});

test('headcount is read against the usual for that type over the last twelve months', function () {
    $quiet = Event::factory()->create(['event_type_id' => $this->social->id, 'event_date' => '2026-11-07']);
    $busy = Event::factory()->create(['event_type_id' => $this->social->id, 'event_date' => '2026-11-14']);
    $spring = Event::factory()->create(['event_type_id' => $this->social->id, 'event_date' => '2026-04-10']);
    $class = Event::factory()->create(['event_type_id' => $this->class->id, 'event_date' => '2026-11-10']);
    // More than twelve months back: not part of "usual".
    $old = Event::factory()->create(['event_type_id' => $this->social->id, 'event_date' => '2025-06-01']);

    comparisonVisits($quiet, 2);
    comparisonVisits($busy, 6);
    comparisonVisits($spring, 4);
    comparisonVisits($class, 1);
    comparisonVisits($old, 50);

    $month = AnalyticsPeriod::forPreset('this_month');

    expect(app(EventAnalytics::class)->usualHeadcounts($month))->toBe([$this->social->id => 4, $this->class->id => 1])
        ->and(comparisonFigures($busy, $month)['usual_headcount'])->toBe(4);

    Livewire::test(EventComparisonWidget::class, ['pageFilters' => ['preset' => 'this_month']])
        ->assertCanSeeTableRecords([$quiet, $busy, $class])
        ->assertCanNotSeeTableRecords([$spring, $old])
        ->assertSee('+2 (usual 4)')
        ->assertSee('-2 (usual 4)');
});

test('private rentals and events still to come are left out', function () {
    $held = Event::factory()->create(['event_type_id' => $this->social->id, 'event_date' => '2026-11-14']);
    $upcoming = Event::factory()->create(['event_type_id' => $this->social->id, 'event_date' => '2026-11-21']);
    $rental = Event::factory()->create(['event_type_id' => $this->social->id, 'event_date' => '2026-11-13']);
    PrivateRental::factory()->create(['event_id' => $rental->id]);

    $ids = app(EventAnalytics::class)->comparisonQuery(AnalyticsPeriod::forPreset('this_month'))->pluck('id')->all();

    expect($ids)->toBe([$held->id]);
});

test('the table follows the page period, filters by type, and sorts by headcount', function () {
    $social = Event::factory()->create(['event_type_id' => $this->social->id, 'event_date' => '2026-11-14']);
    $class = Event::factory()->create(['event_type_id' => $this->class->id, 'event_date' => '2026-11-10']);
    $lastMonth = Event::factory()->create(['event_type_id' => $this->social->id, 'event_date' => '2026-10-10']);

    comparisonVisits($social, 1);
    comparisonVisits($class, 3);

    Livewire::test(EventComparisonWidget::class, ['pageFilters' => ['preset' => 'this_month']])
        ->assertCanSeeTableRecords([$social, $class])
        ->assertCanNotSeeTableRecords([$lastMonth])
        ->sortTable('headcount', 'desc')
        ->assertCanSeeTableRecords([$class, $social], inOrder: true)
        ->filterTable('event_type_id', $this->social->id)
        ->assertCanSeeTableRecords([$social])
        ->assertCanNotSeeTableRecords([$class]);

    Livewire::test(EventComparisonWidget::class, ['pageFilters' => ['preset' => 'last_month']])
        ->assertCanSeeTableRecords([$lastMonth])
        ->assertCanNotSeeTableRecords([$social, $class]);
});

test('the comparison exports as a CSV of what the table shows, with bare numbers', function () {
    $event = Event::factory()->create(['name' => 'Harvest Social', 'event_type_id' => $this->social->id, 'event_date' => '2026-11-14']);
    comparisonVisits($event, 2, ['amount_paid' => 12.5]);

    $response = Livewire::test(EventComparisonWidget::class, ['pageFilters' => ['preset' => 'this_month']])
        ->callAction(TestAction::make('export')->table())
        ->assertFileDownloaded('event-comparison-2026-11-18.csv');

    $csv = base64_decode($response->effects['download']['content']);

    expect($csv)->toContain('Date,Event,Type,Headcount,"Usual Headcount",First-timers,Subscribers,Comps,"Prepaid No-show",Revenue,"Revenue Per Head"')
        ->toContain('2026-11-14,"Harvest Social",Social,2,2,2,0,0,0,25.00,12.50');
});

test('the period\'s events roll up by type and by weekday', function () {
    // Two Saturdays and a Tuesday.
    $first = Event::factory()->create(['event_type_id' => $this->social->id, 'event_date' => '2026-11-07']);
    $second = Event::factory()->create(['event_type_id' => $this->social->id, 'event_date' => '2026-11-14']);
    $class = Event::factory()->create(['event_type_id' => $this->class->id, 'event_date' => '2026-11-10']);

    comparisonVisits($first, 2, ['amount_paid' => 10]);
    comparisonVisits($second, 4, ['amount_paid' => 10]);
    comparisonVisits($class, 1, ['amount_paid' => 5]);

    $month = AnalyticsPeriod::forPreset('this_month');
    $analytics = app(EventAnalytics::class);

    expect($analytics->byEventType($month)->all())->toBe([
        ['name' => 'Social', 'events' => 2, 'average_headcount' => 3, 'average_revenue_cents' => 3000, 'per_head_cents' => 1000],
        ['name' => 'Class', 'events' => 1, 'average_headcount' => 1, 'average_revenue_cents' => 500, 'per_head_cents' => 500],
    ])->and($analytics->byWeekday($month)->pluck('name')->all())->toBe(['Tuesday', 'Saturday']);

    $typeStats = (fn () => $this->getStats())->call(
        Livewire::test(EventTypeRollUpWidget::class, ['pageFilters' => ['preset' => 'this_month']])->instance(),
    );

    expect($typeStats[0]->getLabel())->toBe('Social · 2 events')
        ->and($typeStats[0]->getValue())->toBe('3 a night')
        ->and($typeStats[0]->getDescription())->toBe('$30.00 a night · $10.00 a head')
        ->and($typeStats[1]->getLabel())->toBe('Class · 1 event');

    Livewire::test(EventWeekdayRollUpWidget::class, ['pageFilters' => ['preset' => 'this_month']])
        ->assertSee('Saturday · 2 events');
});

test('the Events tab shows the comparison, and a door volunteer cannot see the widget', function () {
    $this->get('/admin/analytics?tab=events')
        ->assertSuccessful()
        ->assertSee('Events — This week')
        ->assertSee('Export (CSV)');

    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Door]));

    expect(EventComparisonWidget::canView())->toBeFalse();
});
