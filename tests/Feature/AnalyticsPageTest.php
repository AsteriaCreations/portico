<?php

use App\Enums\AddOnKind;
use App\Enums\Role;
use App\Filament\Admin\Pages\Analytics;
use App\Filament\Admin\Widgets\RevenueByEventTypeWidget;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // SubscriptionOverviewWidget reads AddOn::entry() -- always present in
    // a real install via AddOnSeeder, but these tests build their own minimal
    // fixture rather than seeding the whole app.
    AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);

    // A Wednesday mid-month, so "this week" and "last month" can't overlap.
    $this->travelTo('2026-11-18 12:00:00');
});

function analyticsManager(): User
{
    return User::factory()->create(['active' => true, 'role' => Role::Manager]);
}

test('a manager opens analytics on the Overview tab, with the period picker and every tab offered', function () {
    $this->actingAs(analyticsManager())
        ->get('/admin/analytics')
        ->assertSuccessful()
        ->assertSee('Period')
        ->assertSee('This week')
        ->assertSee('Overview')
        ->assertSee('Revenue')
        ->assertSee('Members')
        ->assertSee("Tonight's check-ins")
        ->assertSee('Attendance trend')
        ->assertSee('Revenue trend');
});

test('only the open tab renders its widgets', function () {
    $manager = analyticsManager();

    $this->actingAs($manager)
        ->get('/admin/analytics')
        ->assertDontSee('Outstanding voucher liability')
        ->assertDontSee('Active Membership — By Category');

    $this->actingAs($manager)
        ->get('/admin/analytics?tab=revenue')
        ->assertSuccessful()
        ->assertSee('By event type — This week')
        ->assertSee('Active Regular subscribers')
        ->assertSee('Add-on revenue — This week')
        ->assertSee('Comp cost — This week')
        ->assertSee('Total register variance')
        ->assertSee('Outstanding voucher liability')
        ->assertDontSee("Tonight's check-ins")
        ->assertDontSee('Active Membership — By Category');

    $this->actingAs($manager)
        ->get('/admin/analytics?tab=members')
        ->assertSuccessful()
        ->assertSee('Active Membership — By Category')
        ->assertSee('Visits by category — This week')
        ->assertSee('New Members')
        ->assertDontSee('Outstanding voucher liability');
});

test('an unknown tab in the URL falls back to Overview', function () {
    $this->actingAs(analyticsManager())
        ->get('/admin/analytics?tab=nonsense')
        ->assertSuccessful()
        ->assertSee("Tonight's check-ins");
});

test('the period chosen on the page is the one its widgets report on', function () {
    $manager = analyticsManager();
    $social = EventType::factory()->create(['name' => 'Social']);

    $lastMonth = Event::factory()->create(['event_type_id' => $social->id, 'event_date' => '2026-10-10']);
    Attendance::factory()->for($lastMonth)->create(['checked_in_at' => '2026-10-10 20:00:00', 'amount_paid' => 20]);

    $this->actingAs($manager)
        ->get('/admin/analytics?tab=revenue')
        ->assertSee('By event type — This week')
        ->assertDontSee('1 visit');

    $this->actingAs($manager)
        ->get('/admin/analytics?tab=revenue&filters[preset]=last_month')
        ->assertSuccessful()
        ->assertSee('By event type — Last month')
        ->assertSee('1 visit')
        ->assertSee('$20.00 collected');
});

test('custom dates are offered only for the custom period, and applied once both are set', function () {
    $manager = analyticsManager();
    $social = EventType::factory()->create(['name' => 'Social']);

    $event = Event::factory()->create(['event_type_id' => $social->id, 'event_date' => '2026-09-05']);
    Attendance::factory()->for($event)->create(['checked_in_at' => '2026-09-05 20:00:00', 'amount_paid' => 20]);

    $this->actingAs($manager)
        ->get('/admin/analytics?tab=revenue')
        ->assertDontSee('Until');

    $this->actingAs($manager)
        ->get('/admin/analytics?tab=revenue&filters[preset]=custom&filters[from]=2026-09-01&filters[until]=2026-09-05')
        ->assertSuccessful()
        ->assertSee('Until')
        ->assertSee('1 visit');

    // The last day is included; the day after it is not.
    $this->actingAs($manager)
        ->get('/admin/analytics?tab=revenue&filters[preset]=custom&filters[from]=2026-09-01&filters[until]=2026-09-04')
        ->assertDontSee('1 visit');
});

test('a chart repeats its numbers as a table for screen readers', function () {
    $event = Event::factory()->create(['event_date' => '2026-11-18']);
    Attendance::factory()->for($event)->create(['checked_in_at' => now()]);

    $this->actingAs(analyticsManager())
        ->get('/admin/analytics')
        ->assertSee('<caption>Attendance trend</caption>', false)
        ->assertSee('<th scope="col">Attendance</th>', false)
        ->assertSee('<th scope="row">Nov 16</th>', false);
});

test('the overview tab stays within its query budget', function () {
    $manager = analyticsManager();
    $this->actingAs($manager);

    DB::enableQueryLog();
    $this->get('/admin/analytics')->assertSuccessful();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Two 12-step trend charts (1 and 4 queries a step) plus the panel's own.
    expect($queries)->toBeLessThan(75);
});

test('a door volunteer is forbidden from the analytics page', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);

    $this->actingAs($door)->get('/admin/analytics')->assertForbidden();
});

test('changing the period or the tab on the open page updates it without a reload', function () {
    $this->actingAs(analyticsManager());

    Livewire::withQueryParams(['tab' => 'revenue'])
        ->test(Analytics::class)
        ->assertSet('tab', 'revenue')
        ->assertSet('filters.preset', 'this_week')
        ->set('filters.preset', 'last_month')
        ->assertSet('filters.preset', 'last_month')
        ->set('tab', 'members')
        ->assertSet('tab', 'members')
        ->set('tab', 'nonsense')
        ->assertSet('tab', 'overview');

    // The widgets are handed the page's filters, so they follow the change.
    $widget = Livewire::test(RevenueByEventTypeWidget::class, ['pageFilters' => ['preset' => 'last_month']])->instance();

    expect((fn () => $this->getHeading())->call($widget))->toBe('By event type — Last month');
});
