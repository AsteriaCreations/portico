<?php

use App\Enums\AddOnKind;
use App\Enums\Role;
use App\Filament\Admin\Widgets\ArrivalTimesChartWidget;
use App\Filament\Admin\Widgets\CheckInChannelWidget;
use App\Filament\Admin\Widgets\EventComparisonWidget;
use App\Filament\Admin\Widgets\LengthOfStayWidget;
use App\Filament\Admin\Widgets\OccupancySummaryWidget;
use App\Filament\Admin\Widgets\PeakOccupancyChartWidget;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\OccupancyAdjustment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Analytics\DoorFlowAnalytics;
use App\Support\AnalyticsPeriod;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-11-18 12:00:00');

    AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    $this->manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($this->manager);
});

/**
 * A visit to $event checked in at $arrived (and out at $departed) by the
 * manager, as the desk records it.
 */
function doorVisit(Event $event, string $arrived, ?string $departed = null, array $attributes = []): Attendance
{
    return Attendance::factory()->for($event)->create([
        'checked_in_by' => test()->manager->id,
        'checked_in_at' => $arrived,
        'departed_at' => $departed,
        'created_at' => $arrived,
        ...$attributes,
    ]);
}

function doorStats(string $widget, array $filters = ['preset' => 'this_month']): array
{
    $instance = Livewire::test($widget, ['pageFilters' => $filters])->assertSuccessful()->instance();

    return collect((fn () => $this->getStats())->call($instance))
        ->keyBy(fn ($stat) => $stat->getLabel())
        ->all();
}

function doorChart(string $widget, array $filters = ['preset' => 'this_month']): array
{
    return (fn () => $this->getData())->call(Livewire::test($widget, ['pageFilters' => $filters])->instance());
}

test('a night\'s peak replays arrivals, departures and anonymous departures in order, across every event that night', function () {
    $social = Event::factory()->create(['event_date' => '2026-11-14']);
    $class = Event::factory()->create(['event_date' => '2026-11-14']);

    doorVisit($social, '2026-11-14 20:00:00', '2026-11-14 21:30:00');
    doorVisit($social, '2026-11-14 20:30:00');
    doorVisit($class, '2026-11-14 21:00:00');             // three inside at 21:00
    doorVisit($social, '2026-11-14 22:00:00');            // one left at 21:30, back to three
    // Two people left without being named, recorded at 22:15.
    OccupancyAdjustment::forceCreate(['for_date' => '2026-11-14', 'delta' => -2, 'reason' => 'Two left', 'recorded_by' => $this->manager->id, 'created_at' => '2026-11-14 22:15:00']);
    doorVisit($class, '2026-11-14 23:00:00');             // two
    // After midnight still belongs to the 14th's events.
    doorVisit($social, '2026-11-15 00:30:00');            // three

    $nights = app(DoorFlowAnalytics::class)->nights(AnalyticsPeriod::forPreset('this_month'));

    expect($nights)->toHaveCount(1)
        ->and($nights[0]['date'])->toBe('2026-11-14')
        ->and($nights[0]['arrivals'])->toBe(6)
        ->and($nights[0]['peak'])->toBe(3)
        ->and($nights[0]['peak_at']->toDateTimeString())->toBe('2026-11-14 21:00:00');
});

test('imported visits and prepays that never arrived are not in the nights', function () {
    $event = Event::factory()->create(['event_date' => '2026-11-07']);
    // Imported: no checked_in_by, arrival at midnight.
    Attendance::factory()->count(5)->for($event)->create(['checked_in_by' => null, 'checked_in_at' => '2026-11-07 00:00:00']);
    Attendance::factory()->for($event)->create(['checked_in_by' => $this->manager->id, 'checked_in_at' => null]);

    expect(app(DoorFlowAnalytics::class)->nights(AnalyticsPeriod::forPreset('this_month')))->toBe([]);

    $stats = doorStats(OccupancySummaryWidget::class);

    expect($stats)->toHaveCount(1)
        ->and($stats['Nights']->getDescription())->toBe('No check-ins recorded in the app in this period');
});

test('the summary picks out the busiest night and counts nights at or near capacity', function () {
    MembershipSetting::current()->update(['venue_capacity' => 3]);

    $busy = Event::factory()->create(['event_date' => '2026-11-14']);
    foreach (['20:00', '20:10', '20:20'] as $time) {
        doorVisit($busy, "2026-11-14 {$time}:00");
    }
    $quiet = Event::factory()->create(['event_date' => '2026-11-07']);
    doorVisit($quiet, '2026-11-07 20:00:00');

    $stats = doorStats(OccupancySummaryWidget::class);

    expect($stats['Nights']->getValue())->toBe(2)
        ->and($stats['Busiest night']->getValue())->toBe(3)
        ->and($stats['Busiest night']->getDescription())->toBe('Nov 14, 2026, at 8:20 PM')
        ->and($stats['Average peak']->getValue())->toBe(2)
        ->and($stats['Nights at capacity']->getValue())->toBe(1)
        ->and($stats['Nights at capacity']->getDescription())->toBe('Capacity 3 (today\'s setting)');

    $chart = doorChart(PeakOccupancyChartWidget::class);

    expect($chart['labels'])->toBe(['Nov 7, 2026', 'Nov 14, 2026'])
        ->and($chart['datasets'][0]['data'])->toBe([1, 3])
        ->and($chart['datasets'][1]['data'])->toBe([3, 3]);

    // No capacity set: no capacity tiles, no capacity line.
    MembershipSetting::current()->update(['venue_capacity' => null]);

    expect(doorStats(OccupancySummaryWidget::class))->not->toHaveKey('Nights at capacity')
        ->and(doorChart(PeakOccupancyChartWidget::class)['datasets'])->toHaveCount(1);
});

test('arrivals are averaged by hour of the night, evening first, with empty hours in between', function () {
    $first = Event::factory()->create(['event_date' => '2026-11-07']);
    $second = Event::factory()->create(['event_date' => '2026-11-14']);

    doorVisit($first, '2026-11-07 20:15:00');
    doorVisit($first, '2026-11-07 20:45:00');
    doorVisit($second, '2026-11-14 20:30:00');
    doorVisit($second, '2026-11-15 00:10:00');

    $hours = app(DoorFlowAnalytics::class)->arrivalsByHour(AnalyticsPeriod::forPreset('this_month'));

    expect(array_column($hours, 'hour'))->toBe([20, 21, 22, 23, 0])
        ->and(array_column($hours, 'average'))->toBe([1.5, 0.0, 0.0, 0.0, 0.5])
        ->and($hours[0]['label'])->toBe('8:00 PM');

    expect(doorChart(ArrivalTimesChartWidget::class)['labels'])->toBe(['8:00 PM', '9:00 PM', '10:00 PM', '11:00 PM', '12:00 AM']);
});

test('length of stay comes from recorded departures and says how many it is based on', function () {
    $event = Event::factory()->create(['event_date' => '2026-11-14']);

    doorVisit($event, '2026-11-14 20:00:00', '2026-11-14 20:40:00');  // 40 min
    doorVisit($event, '2026-11-14 20:00:00', '2026-11-14 22:00:00');  // 2h
    doorVisit($event, '2026-11-14 20:00:00', '2026-11-15 00:30:00');  // 4h30
    doorVisit($event, '2026-11-14 20:00:00');                         // never marked leaving
    // A departure before the arrival is a data slip, not a stay.
    doorVisit($event, '2026-11-14 21:00:00', '2026-11-14 20:00:00');

    expect(app(DoorFlowAnalytics::class)->lengthOfStay(AnalyticsPeriod::forPreset('this_month')))->toBe([
        'arrivals' => 5,
        'sample' => 3,
        'median_minutes' => 120,
        'average_minutes' => 143,
        'under_hour' => 1,
        'one_to_three' => 1,
        'over_three' => 1,
    ]);

    $stats = doorStats(LengthOfStayWidget::class);

    expect($stats['Typical stay']->getValue())->toBe('2h')
        ->and($stats['Typical stay']->getDescription())->toBe('From 3 of 5 visits with a departure recorded')
        ->and($stats['Average stay']->getValue())->toBe('2h 23m')
        ->and($stats['Under an hour']->getDescription())->toBe('33% of those visits');
});

test('each arrival is put down to one way of checking in', function () {
    $event = Event::factory()->create(['event_date' => '2026-11-14']);

    doorVisit($event, '2026-11-14 20:00:00');
    doorVisit($event, '2026-11-14 20:05:00');
    doorVisit($event, '2026-11-14 20:10:00', attributes: ['payment_method' => PaymentMethod::KIOSK]);
    // Booked on the 10th, marked arrived on the night.
    doorVisit($event, '2026-11-14 20:15:00', attributes: ['created_at' => '2026-11-10 13:00:00']);
    // A staff login checking in as their own member record.
    $staffMember = Member::factory()->create();
    $staff = User::factory()->create(['active' => true, 'role' => Role::Door, 'member_id' => $staffMember->id]);
    doorVisit($event, '2026-11-14 19:00:00', attributes: ['member_id' => $staffMember->id, 'checked_in_by' => $staff->id]);
    // Imported.
    Attendance::factory()->for($event)->create(['checked_in_by' => null, 'checked_in_at' => '2026-11-14 00:00:00']);

    expect(app(DoorFlowAnalytics::class)->channels(AnalyticsPeriod::forPreset('this_month')))->toBe([
        'desk' => 2,
        'kiosk' => 1,
        'prepaid' => 1,
        'staff' => 1,
        'imported' => 1,
    ]);

    $stats = doorStats(CheckInChannelWidget::class);

    expect($stats['At the desk']->getDescription())->toBe('33% of check-ins')
        ->and($stats['Prepaid, marked arrived']->getDescription())->toBe('17% of check-ins · Booked on an earlier day')
        ->and($stats)->toHaveKey('Staff checking themselves in');
});

test('the event comparison shows its night\'s peak, in the table and the CSV', function () {
    $event = Event::factory()->create(['name' => 'Harvest Social', 'event_date' => '2026-11-14']);
    doorVisit($event, '2026-11-14 20:00:00');
    doorVisit($event, '2026-11-14 20:30:00');

    $response = Livewire::test(EventComparisonWidget::class, ['pageFilters' => ['preset' => 'this_month']])
        ->assertSee('Most inside')
        ->callAction(TestAction::make('export')->table());

    expect(base64_decode($response->effects['download']['content']))
        ->toContain('"Revenue Per Head","Most Inside"')
        ->toContain(",2\n");
});

test('the Operations tab carries the door widgets', function () {
    $this->get('/admin/analytics?tab=operations')
        ->assertSuccessful()
        ->assertSee('How full it got — This week')
        ->assertSee('How people were checked in — This week')
        ->assertSee('Most inside at once, night by night')
        ->assertSee('When people arrive')
        ->assertSee('How long people stay — This week');
});
