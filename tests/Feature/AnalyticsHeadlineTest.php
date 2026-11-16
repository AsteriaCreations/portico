<?php

use App\Enums\AddOnKind;
use App\Enums\Role;
use App\Filament\Admin\Resources\Members\Pages\ListMembers;
use App\Filament\Admin\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Admin\Widgets\MembershipCompositionWidget;
use App\Filament\Admin\Widgets\PaymentMethodMixWidget;
use App\Filament\Admin\Widgets\PeriodHeadlineWidget;
use App\Models\AddOn;
use App\Models\AddOnDayPass;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Models\MiscellaneousPayment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Analytics\AttendanceAnalytics;
use App\Services\Analytics\RevenueAnalytics;
use App\Support\AnalyticsPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // A Wednesday: this week is Mon 16 - Sun 22 Nov, and has run to the 18th.
    $this->travelTo('2026-11-18 12:00:00');

    $this->entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));
});

/**
 * An arrived visit by $member on $day, on that day's own event.
 */
function headlineVisit(Member $member, string $day, float $paid = 0, ?string $method = null): Attendance
{
    return Attendance::factory()
        ->for($member)
        ->for(Event::factory()->create(['event_date' => $day]))
        ->create(['checked_in_at' => $day.' 20:00:00', 'amount_paid' => $paid, 'payment_method' => $method]);
}

/**
 * @return array<string, Stat> the widget's stats keyed by label
 */
function headlineStats(string $widget, array $filters = []): array
{
    $instance = Livewire::test($widget, ['pageFilters' => $filters])->assertSuccessful()->instance();

    return collect((fn () => $this->getStats())->call($instance))
        ->keyBy(fn ($stat) => $stat->getLabel())
        ->all();
}

test('a first-time visitor is someone whose very first arrival fell in the period', function () {
    $newcomer = Member::factory()->create();
    headlineVisit($newcomer, '2026-11-17');

    $returning = Member::factory()->create();
    headlineVisit($returning, '2026-10-10');
    headlineVisit($returning, '2026-11-17');

    // Came before these records began (an import's yearly totals).
    $imported = Member::factory()->create(['prior_visits' => 3]);
    headlineVisit($imported, '2026-11-16');

    // Prepaid for this week but never arrived: not a visit at all.
    $prepaid = Member::factory()->create();
    Attendance::factory()->for($prepaid)->for(Event::factory()->create(['event_date' => '2026-11-17']))->create(['checked_in_at' => null]);

    $week = AnalyticsPeriod::forPreset('this_week');
    $analytics = app(AttendanceAnalytics::class);

    expect($analytics->arrivals($week))->toBe(3)
        ->and($analytics->uniqueVisitors($week))->toBe(3)
        ->and($analytics->firstTimeVisitors($week))->toBe(1)
        // Last month it was the returning member who was new.
        ->and($analytics->firstTimeVisitors(AnalyticsPeriod::forPreset('last_month')))->toBe(1);

    // The Members list filter the tile links to finds the same people.
    Livewire::test(ListMembers::class)
        ->filterTable('first_visit', ['first_visit_from' => '2026-11-16', 'first_visit_until' => '2026-11-22'])
        ->assertCanSeeTableRecords([$newcomer])
        ->assertCanNotSeeTableRecords([$returning, $imported, $prepaid]);
});

test('two visits by one person in the period are two visits and one visitor', function () {
    $member = Member::factory()->create();
    headlineVisit($member, '2026-11-16');
    headlineVisit($member, '2026-11-17');

    $week = AnalyticsPeriod::forPreset('this_week');

    expect(app(AttendanceAnalytics::class)->arrivals($week))->toBe(2)
        ->and(app(AttendanceAnalytics::class)->uniqueVisitors($week))->toBe(1);
});

test('revenue splits by payment method, nets to the same total as the streams, and skips $0 rows', function () {
    headlineVisit(Member::factory()->create(), '2026-11-17', 20, 'cash');
    headlineVisit(Member::factory()->create(), '2026-11-17', 10, 'venmo');
    // Covered by a subscription: nothing paid, no method.
    headlineVisit(Member::factory()->create(), '2026-11-17');
    // Money with no method on it, as on an imported row.
    headlineVisit(Member::factory()->create(), '2026-11-16', 7);
    // Last week: outside the period.
    headlineVisit(Member::factory()->create(), '2026-11-10', 999, 'cash');

    Subscription::factory()->create(['add_on_id' => $this->entry->id, 'paid_on' => '2026-11-18', 'amount_paid' => 60, 'payment_method' => 'cash']);
    MiscellaneousPayment::factory()->create(['amount' => 5, 'payment_method' => 'cash']);
    AddOnDayPass::factory()->create(['amount_paid' => 15, 'payment_method' => 'venmo']);

    $week = AnalyticsPeriod::forPreset('this_week');
    $methods = app(RevenueAnalytics::class)->byPaymentMethod($week);

    expect($methods->all())->toBe([
        ['code' => 'cash', 'cents' => 8500, 'payments' => 3],
        ['code' => 'venmo', 'cents' => 2500, 'payments' => 2],
        ['code' => null, 'cents' => 700, 'payments' => 1],
    ])->and($methods->sum('cents'))->toBe(app(RevenueAnalytics::class)->totalCents($week));

    $stats = headlineStats(PaymentMethodMixWidget::class);

    expect(array_keys($stats))->toBe(['Cash', 'Venmo', 'No method recorded'])
        ->and($stats['Cash']->getValue())->toBe('$85.00')
        ->and($stats['Cash']->getDescription())->toBe('73% of the total · 3 payments')
        ->and($stats['No method recorded']->getDescription())->toBe('6% of the total · 1 payment');
});

test('the headline compares a period still under way with the same stretch of the one before', function () {
    // This week so far (Mon-Wed): two visits, $30.
    headlineVisit(Member::factory()->create(), '2026-11-16', 20, 'cash');
    headlineVisit(Member::factory()->create(), '2026-11-17', 10, 'cash');
    // Last week, Monday: inside the same stretch.
    headlineVisit(Member::factory()->create(), '2026-11-09', 40, 'cash');
    // Last week, Friday: later in that week than this one has run, so left out.
    headlineVisit(Member::factory()->create(), '2026-11-13', 500, 'cash');

    $widget = Livewire::test(PeriodHeadlineWidget::class)->instance();
    $stats = headlineStats(PeriodHeadlineWidget::class);

    expect((fn () => $this->getHeading())->call($widget))->toBe('This week, so far')
        ->and($stats['Visits']->getValue())->toBe('2')
        ->and($stats['Visits']->getDescription())->toBe('Up 100% on the period before (1)')
        ->and($stats['Visits']->getColor())->toBe('success')
        ->and($stats['Different visitors']->getValue())->toBe('2')
        ->and($stats['First-time visitors']->getValue())->toBe('2')
        ->and($stats['Revenue']->getValue())->toBe('$30.00')
        ->and($stats['Revenue']->getDescription())->toBe('Down 25% on the period before ($40.00)')
        ->and($stats['Revenue']->getColor())->toBe('warning');
});

test('a finished period is compared whole, and says so when there is nothing before it', function () {
    headlineVisit(Member::factory()->create(), '2026-10-30');

    $widget = Livewire::test(PeriodHeadlineWidget::class, ['pageFilters' => ['preset' => 'last_month']])->instance();
    $stats = headlineStats(PeriodHeadlineWidget::class, ['preset' => 'last_month']);

    expect((fn () => $this->getHeading())->call($widget))->toBe('Last month')
        ->and($stats['Visits']->getValue())->toBe('1')
        ->and($stats['Visits']->getDescription())->toBe('Nothing in the period before to compare with')
        ->and($stats['Revenue']->getDescription())->toBe('Nothing in the period before to compare with');
});

test('the subscriber tile is the period\'s month against the month before, and links to that month\'s subscriptions', function () {
    $november = Subscription::factory()->count(3)->create(['add_on_id' => $this->entry->id, 'covered_month' => '2026-11-01']);
    $october = Subscription::factory()->count(2)->create(['add_on_id' => $this->entry->id, 'covered_month' => '2026-10-01']);
    // Another plan's subscription doesn't count as a Regular subscriber.
    $otherPlan = Subscription::factory()->create(['covered_month' => '2026-11-01']);

    $stat = headlineStats(PeriodHeadlineWidget::class)['Regular subscribers, Nov 2026'];

    expect($stat->getValue())->toBe('3')
        ->and($stat->getDescription())->toBe('Up 50% on Oct 2026 (2)')
        ->and(urldecode($stat->getUrl()))->toContain('tableFilters[add_on_id][value]='.$this->entry->id)
        ->and(urldecode($stat->getUrl()))->toContain('tableFilters[covered_month][month]=2026-11-01');

    Livewire::test(ListSubscriptions::class)
        ->filterTable('add_on_id', $this->entry->id)
        ->filterTable('covered_month', ['month' => '2026-11-01'])
        ->assertCanSeeTableRecords($november)
        ->assertCanNotSeeTableRecords([...$october, $otherPlan]);
});

test('the first-time visitor tile links to the Members list for the stretch it counted', function () {
    $url = urldecode(headlineStats(PeriodHeadlineWidget::class)['First-time visitors']->getUrl());

    expect($url)->toContain('/admin/members')
        ->toContain('tableFilters[first_visit][first_visit_from]=2026-11-16')
        ->toContain('tableFilters[first_visit][first_visit_until]=2026-11-18');
});

test('each category in the membership mix links to its active members', function () {
    $regular = Category::factory()->create(['name' => 'Regular']);
    Member::factory()->create(['category_id' => $regular->id, 'is_active' => true]);

    $url = urldecode(headlineStats(MembershipCompositionWidget::class)['Regular']->getUrl());

    expect($url)->toContain('tableFilters[category_id][value]='.$regular->id)
        ->toContain('tableFilters[is_active][value]=1');
});

test('the new tiles are on their tabs', function () {
    $this->get('/admin/analytics')
        ->assertSuccessful()
        ->assertSee('This week, so far')
        ->assertSee('First-time visitors');

    $this->get('/admin/analytics?tab=revenue')
        ->assertSuccessful()
        ->assertSee('How it was paid — This week');
});
