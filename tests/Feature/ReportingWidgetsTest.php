<?php

use App\Enums\AddOnKind;
use App\Enums\EntryCoverageSource;
use App\Enums\Role;
use App\Filament\Admin\Widgets\AddOnRevenueWidget;
use App\Filament\Admin\Widgets\CompCostWidget;
use App\Filament\Admin\Widgets\MembershipCompositionWidget;
use App\Filament\Admin\Widgets\MonthlyRevenueChartWidget;
use App\Filament\Admin\Widgets\NewMembersChartWidget;
use App\Filament\Admin\Widgets\RegisterVarianceWidget;
use App\Filament\Admin\Widgets\ScheduledJobsWidget;
use App\Filament\Admin\Widgets\SubscriptionOverviewWidget;
use App\Filament\Admin\Widgets\TonightOverviewWidget;
use App\Filament\Admin\Widgets\VoucherLiabilityWidget;
use App\Filament\Admin\Widgets\WeeklyAttendanceChartWidget;
use App\Filament\Admin\Widgets\WeeklyCategoryBreakdownWidget;
use App\Filament\Admin\Widgets\WeeklySummaryWidget;
use App\Models\AddOn;
use App\Models\AddOnDayPass;
use App\Models\Attendance;
use App\Models\AttendanceAddOn;
use App\Models\Category;
use App\Models\CommandRun;
use App\Models\CompReason;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\MiscellaneousPayment;
use App\Models\RegisterShift;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test("tonight's overview widget counts only today's arrived attendance", function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);

    $tonightEvent = Event::factory()->create(['event_date' => today()]);
    $yesterdayEvent = Event::factory()->create(['event_date' => today()->subDay()]);

    Attendance::factory()->for($tonightEvent)->create(['checked_in_at' => now(), 'amount_paid' => 20]);
    Attendance::factory()->for($tonightEvent)->create(['checked_in_at' => now(), 'amount_paid' => 15]);
    // Prepaid for tonight but not yet arrived — should not count.
    Attendance::factory()->for($tonightEvent)->create(['checked_in_at' => null, 'amount_paid' => 40]);
    // Arrived, but for a different day's event — should not count.
    Attendance::factory()->for($yesterdayEvent)->create(['checked_in_at' => now(), 'amount_paid' => 100]);

    Livewire::test(TonightOverviewWidget::class)->assertSuccessful();

    $widget = new TonightOverviewWidget;
    $stats = (fn () => $this->getStats())->call($widget);

    expect($stats[0]->getValue())->toBe(2)
        ->and($stats[1]->getValue())->toBe('$35.00');
});

test("tonight's overview widget is restricted to manager and up", function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($door);
    expect(TonightOverviewWidget::canView())->toBeFalse();

    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);
    expect(TonightOverviewWidget::canView())->toBeTrue();
});

test('the business/technical reporting widgets no longer render on the dashboard, for any role', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($door)
        ->get('/admin')
        ->assertSuccessful()
        ->assertDontSee("Tonight's check-ins")
        ->assertDontSee("Tonight's door take");

    $admin = User::factory()->create(['active' => true, 'role' => Role::Admin]);
    $this->actingAs($admin)
        ->get('/admin')
        ->assertSuccessful()
        ->assertDontSee("Tonight's check-ins")
        ->assertDontSee("Tonight's door take")
        ->assertDontSee('Database backup')
        ->assertDontSee('Comp reward vouchers');
});

test('weekly summary widget groups revenue by type for the current calendar week only', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);

    $social = EventType::factory()->create(['name' => 'Social', 'sort_order' => 0]);
    $class = EventType::factory()->create(['name' => 'Class', 'sort_order' => 1]);

    $socialEvent = Event::factory()->create(['event_type_id' => $social->id, 'event_date' => today()]);
    $classEvent = Event::factory()->create(['event_type_id' => $class->id, 'event_date' => today()]);
    $lastWeekSocialEvent = Event::factory()->create(['event_type_id' => $social->id, 'event_date' => now()->subWeeks(2)]);

    Attendance::factory()->for($socialEvent)->create(['checked_in_at' => now(), 'amount_paid' => 10]);
    Attendance::factory()->for($socialEvent)->create(['checked_in_at' => now(), 'amount_paid' => 25]);
    Attendance::factory()->for($classEvent)->create(['checked_in_at' => now(), 'amount_paid' => 5]);
    Attendance::factory()->for($lastWeekSocialEvent)->create(['checked_in_at' => now(), 'amount_paid' => 999]);

    Livewire::test(WeeklySummaryWidget::class)->assertSuccessful();

    $widget = new WeeklySummaryWidget;
    $stats = (fn () => $this->getStats())->call($widget);

    expect($stats)->toHaveCount(2);

    $socialStat = collect($stats)->first(fn ($stat) => $stat->getLabel() === 'Social');
    expect($socialStat->getValue())->toBe('2 visits')
        ->and($socialStat->getDescription())->toBe('$35.00 collected');
});

test('weekly summary widget is restricted to manager and up', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($door);
    expect(WeeklySummaryWidget::canView())->toBeFalse();

    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);
    expect(WeeklySummaryWidget::canView())->toBeTrue();
});

test('weekly category breakdown widget groups revenue by category for the current calendar week only', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);

    $regular = Category::factory()->create(['name' => 'Regular', 'sort_order' => 0]);
    $guest = Category::factory()->create(['name' => 'Guest', 'sort_order' => 1]);

    $regularMember = Member::factory()->create(['category_id' => $regular->id]);
    $anotherRegularMember = Member::factory()->create(['category_id' => $regular->id]);
    $guestMember = Member::factory()->create(['category_id' => $guest->id]);

    Attendance::factory()->for($regularMember)->create(['checked_in_at' => now(), 'amount_paid' => 10]);
    Attendance::factory()->for($anotherRegularMember)->create(['checked_in_at' => now(), 'amount_paid' => 25]);
    Attendance::factory()->for($guestMember)->create(['checked_in_at' => now(), 'amount_paid' => 5]);
    // Arrived last week — should not count.
    Attendance::factory()->for($regularMember)->create(['checked_in_at' => now()->subWeeks(2), 'amount_paid' => 999]);

    Livewire::test(WeeklyCategoryBreakdownWidget::class)->assertSuccessful();

    $widget = new WeeklyCategoryBreakdownWidget;
    $stats = (fn () => $this->getStats())->call($widget);

    expect($stats)->toHaveCount(2);

    $regularStat = collect($stats)->first(fn ($stat) => $stat->getLabel() === 'Regular');
    expect($regularStat->getValue())->toBe('2 visits')
        ->and($regularStat->getDescription())->toBe('$35.00 collected');
});

test('weekly category breakdown widget is restricted to manager and up', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($door);
    expect(WeeklyCategoryBreakdownWidget::canView())->toBeFalse();

    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);
    expect(WeeklyCategoryBreakdownWidget::canView())->toBeTrue();
});

test('weekly attendance chart widget counts arrivals per week, excluding prepays and older visits', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);

    $event = Event::factory()->create();

    Attendance::factory()->for($event)->create(['checked_in_at' => now()]);
    Attendance::factory()->for($event)->create(['checked_in_at' => now()]);
    // Prepaid, never arrived — should not count.
    Attendance::factory()->for($event)->create(['checked_in_at' => null]);
    // Arrived, but well outside the 8-week window — should not count.
    Attendance::factory()->for($event)->create(['checked_in_at' => now()->subWeeks(20)]);

    Livewire::test(WeeklyAttendanceChartWidget::class)->assertSuccessful();

    $widget = new WeeklyAttendanceChartWidget;
    $data = (fn () => $this->getData())->call($widget);

    expect($data['labels'])->toHaveCount(8)
        ->and($data['datasets'][0]['data'])->toHaveCount(8)
        ->and(array_sum($data['datasets'][0]['data']))->toBe(2)
        ->and(end($data['datasets'][0]['data']))->toBe(2);
});

test('weekly attendance chart widget is restricted to manager and up', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($door);
    expect(WeeklyAttendanceChartWidget::canView())->toBeFalse();

    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);
    expect(WeeklyAttendanceChartWidget::canView())->toBeTrue();
});

test('weekly attendance chart widget forces whole-number y-axis ticks', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);

    $widget = new WeeklyAttendanceChartWidget;
    $options = (fn () => $this->getOptions())->call($widget);

    expect($options['scales']['y']['ticks']['stepSize'])->toBe(1)
        ->and($options['scales']['y']['ticks']['precision'])->toBe(0);
});

test('the scheduled jobs widget is restricted to admin and up', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);
    expect(ScheduledJobsWidget::canView())->toBeFalse();

    $admin = User::factory()->create(['active' => true, 'role' => Role::Admin]);
    $this->actingAs($admin);
    expect(ScheduledJobsWidget::canView())->toBeTrue();
});

test('the scheduled jobs widget reports "Never run" for a command with no CommandRun row', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Admin]));

    Livewire::test(ScheduledJobsWidget::class)->assertSuccessful();

    $widget = new ScheduledJobsWidget;
    $stats = (fn () => $this->getStats())->call($widget);

    expect($stats[0]->getValue())->toBe('Never run')
        ->and($stats[0]->getColor())->toBe('danger');
});

test('the scheduled jobs widget shows a recent success in green', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Admin]));
    CommandRun::recordSuccess('backup:database');

    $widget = new ScheduledJobsWidget;
    $stats = (fn () => $this->getStats())->call($widget);

    expect($stats[0]->getValue())->toBe(CommandRun::firstWhere('command', 'backup:database')->last_success_at->diffForHumans())
        ->and($stats[0]->getColor())->toBe('success')
        ->and($stats[0]->getDescription())->toBeNull();
});

test('the scheduled jobs widget flags a stale last-success as danger', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Admin]));
    CommandRun::create(['command' => 'backup:database', 'last_success_at' => now()->subHours(30)]);

    $widget = new ScheduledJobsWidget;
    $stats = (fn () => $this->getStats())->call($widget);

    expect($stats[0]->getColor())->toBe('danger');
});

test('the scheduled jobs widget flags a failure after the last success as danger, with a description', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Admin]));
    CommandRun::create([
        'command' => 'backup:database',
        'last_success_at' => now()->subDay(),
        'last_failure_at' => now()->subHour(),
        'last_failure_message' => 'mysqldump failed: access denied',
    ]);

    $widget = new ScheduledJobsWidget;
    $stats = (fn () => $this->getStats())->call($widget);

    expect($stats[0]->getColor())->toBe('danger')
        ->and($stats[0]->getDescription())->toContain('Failing since');
});

test('voucher liability widget sums every voucher amount, positive and negative, across all members', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);

    Voucher::factory()->create(['amount' => 25]);
    Voucher::factory()->create(['amount' => 40]);
    Voucher::factory()->create(['amount' => -15]); // a redemption/correction row

    Livewire::test(VoucherLiabilityWidget::class)->assertSuccessful();

    $widget = new VoucherLiabilityWidget;
    $stats = (fn () => $this->getStats())->call($widget);

    expect($stats[0]->getValue())->toBe('$50.00');
});

test('voucher liability widget is restricted to manager and up', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($door);
    expect(VoucherLiabilityWidget::canView())->toBeFalse();

    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);
    expect(VoucherLiabilityWidget::canView())->toBeTrue();
});

test('voucher liability widget is hidden once vouchers_enabled is off, restored once re-enabled', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);

    MembershipSetting::current()->update(['vouchers_enabled' => false]);
    expect(VoucherLiabilityWidget::canView())->toBeFalse();

    MembershipSetting::current()->update(['vouchers_enabled' => true]);
    expect(VoucherLiabilityWidget::canView())->toBeTrue();
});

test('subscription overview widget counts active subscribers this month by plan type and revenue this week', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);

    $entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    $pool = AddOn::create(['name' => AddOn::POOL_NAME, 'subscribable' => true, 'priced_per_event' => true]);

    $thisMonth = now()->startOfMonth()->toDateString();
    // Subtracting from the 1st avoids Carbon's month-overflow quirk on a
    // 31st (now()->subMonth() from July 31 lands back on July 1, not June).
    $lastMonth = now()->startOfMonth()->subMonth()->toDateString();

    Subscription::factory()->create(['add_on_id' => $entry->id, 'covered_month' => $thisMonth, 'paid_on' => now(), 'amount_paid' => 60]);
    Subscription::factory()->create(['add_on_id' => $entry->id, 'covered_month' => $thisMonth, 'paid_on' => now(), 'amount_paid' => 60]);
    Subscription::factory()->create(['add_on_id' => $pool->id, 'covered_month' => $thisMonth, 'paid_on' => now(), 'amount_paid' => 15]);
    // Covered last month, not this one — should not count as "active" this
    // month, but paid today, so it still counts toward this week's revenue
    // (revenue windows on paid_on, the transaction date, not covered_month).
    Subscription::factory()->create(['add_on_id' => $entry->id, 'covered_month' => $lastMonth, 'paid_on' => now(), 'amount_paid' => 60]);
    // Covered this month but paid last week — still an "active" subscriber
    // this month, just excluded from the this-week revenue figure.
    Subscription::factory()->create(['add_on_id' => $entry->id, 'covered_month' => $thisMonth, 'paid_on' => now()->subWeeks(2), 'amount_paid' => 999]);

    Livewire::test(SubscriptionOverviewWidget::class)->assertSuccessful();

    $widget = new SubscriptionOverviewWidget;
    $stats = (fn () => $this->getStats())->call($widget);

    $regularStat = collect($stats)->first(fn ($stat) => $stat->getLabel() === 'Active Regular subscribers');
    $poolStat = collect($stats)->first(fn ($stat) => $stat->getLabel() === 'Active Pool subscribers');
    $revenueStat = collect($stats)->first(fn ($stat) => $stat->getLabel() === 'Subscription revenue this week');

    expect($regularStat->getValue())->toBe(3)
        ->and($poolStat->getValue())->toBe(1)
        ->and($revenueStat->getValue())->toBe('$195.00');
});

test('subscription overview widget omits the pool stat once pool_enabled is off, restores once re-enabled', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);

    AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    AddOn::create(['name' => AddOn::POOL_NAME, 'subscribable' => true, 'priced_per_event' => true]);

    MembershipSetting::current()->update(['pool_enabled' => false]);
    $widget = new SubscriptionOverviewWidget;
    $stats = (fn () => $this->getStats())->call($widget);
    expect(collect($stats)->contains(fn ($stat) => $stat->getLabel() === 'Active Pool subscribers'))->toBeFalse();

    MembershipSetting::current()->update(['pool_enabled' => true]);
    $stats = (fn () => $this->getStats())->call($widget);
    expect(collect($stats)->contains(fn ($stat) => $stat->getLabel() === 'Active Pool subscribers'))->toBeTrue();
});

test('subscription overview widget is restricted to manager and up', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($door);
    expect(SubscriptionOverviewWidget::canView())->toBeFalse();

    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);
    expect(SubscriptionOverviewWidget::canView())->toBeTrue();
});

test('monthly revenue chart widget buckets event, subscription, and other revenue by month, excluding data outside the 12-month window', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);

    $event = Event::factory()->create();
    Attendance::factory()->for($event)->create(['checked_in_at' => now(), 'amount_paid' => 50]);
    Subscription::factory()->create(['paid_on' => now(), 'amount_paid' => 60]);
    MiscellaneousPayment::factory()->create(['amount' => 20]);
    AddOnDayPass::factory()->create(['amount_paid' => 15]);

    // 13 months ago — outside the 12-month rolling window, should not count.
    Attendance::factory()->for($event)->create(['checked_in_at' => now()->startOfMonth()->subMonths(13), 'amount_paid' => 999]);

    Livewire::test(MonthlyRevenueChartWidget::class)->assertSuccessful();

    $widget = new MonthlyRevenueChartWidget;
    $data = (fn () => $this->getData())->call($widget);

    expect($data['labels'])->toHaveCount(12)
        ->and($data['datasets'])->toHaveCount(3)
        ->and(array_sum($data['datasets'][0]['data']))->toBe(50.0)
        ->and(array_sum($data['datasets'][1]['data']))->toBe(60.0)
        ->and(array_sum($data['datasets'][2]['data']))->toBe(35.0)
        ->and(end($data['datasets'][0]['data']))->toBe(50.0);
});

test('monthly revenue chart widget stacks its scales', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);

    $widget = new MonthlyRevenueChartWidget;
    $options = (fn () => $this->getOptions())->call($widget);

    expect($options['scales']['x']['stacked'])->toBeTrue()
        ->and($options['scales']['y']['stacked'])->toBeTrue();
});

test('monthly revenue chart widget is restricted to manager and up', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($door);
    expect(MonthlyRevenueChartWidget::canView())->toBeFalse();

    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);
    expect(MonthlyRevenueChartWidget::canView())->toBeTrue();
});

test('add-on revenue widget groups revenue by snapshotted name for the current calendar week only', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);

    $thisWeekAttendance = Attendance::factory()->create(['checked_in_at' => now()]);
    AttendanceAddOn::factory()->for($thisWeekAttendance, 'attendance')->create(['name' => 'Sleepover', 'price' => 30]);
    AttendanceAddOn::factory()->for($thisWeekAttendance, 'attendance')->create(['name' => 'Sleepover', 'price' => 30]);
    AttendanceAddOn::factory()->for($thisWeekAttendance, 'attendance')->create(['name' => 'Private room rental', 'price' => 50]);

    // Sold last week — should not count. The widget windows on the add-on's
    // own snapshot timestamp (when it was purchased), not the attendance
    // row's checked_in_at.
    $lastWeekAttendance = Attendance::factory()->create(['checked_in_at' => now()->subWeeks(2)]);
    AttendanceAddOn::factory()->for($lastWeekAttendance, 'attendance')->create(['name' => 'Sleepover', 'price' => 999, 'created_at' => now()->subWeeks(2)]);

    Livewire::test(AddOnRevenueWidget::class)->assertSuccessful();

    $widget = new AddOnRevenueWidget;
    $stats = (fn () => $this->getStats())->call($widget);

    expect($stats)->toHaveCount(2);

    $sleepoverStat = collect($stats)->first(fn ($stat) => $stat->getLabel() === 'Sleepover');
    expect($sleepoverStat->getValue())->toBe('2 sold')
        ->and($sleepoverStat->getDescription())->toBe('$60.00 collected');
});

test('add-on revenue widget is restricted to manager and up', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($door);
    expect(AddOnRevenueWidget::canView())->toBeFalse();

    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);
    expect(AddOnRevenueWidget::canView())->toBeTrue();
});

test('add-on revenue widget is hidden once add_ons_enabled is off, restored once re-enabled', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);

    MembershipSetting::current()->update(['add_ons_enabled' => false]);
    expect(AddOnRevenueWidget::canView())->toBeFalse();

    MembershipSetting::current()->update(['add_ons_enabled' => true]);
    expect(AddOnRevenueWidget::canView())->toBeTrue();
});

test('comp cost widget groups foregone revenue by comp reason for event comps this week only', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);

    $presenter = CompReason::factory()->create(['name' => 'Presenter', 'sort_order' => 0]);
    $houseSub = CompReason::factory()->create(['name' => 'House Sub', 'sort_order' => 1]);

    Attendance::factory()->create([
        'checked_in_at' => now(),
        'comp_reason_id' => $presenter->id,
        'entry_covered_by' => EntryCoverageSource::EventComp,
        'entry_coverage' => 20,
    ]);
    Attendance::factory()->create([
        'checked_in_at' => now(),
        'comp_reason_id' => $presenter->id,
        'entry_covered_by' => EntryCoverageSource::EventComp,
        'entry_coverage' => 20,
    ]);
    Attendance::factory()->create([
        'checked_in_at' => now(),
        'comp_reason_id' => $houseSub->id,
        'entry_covered_by' => EntryCoverageSource::EventComp,
        'entry_coverage' => 15,
    ]);
    // A regular-subscription-covered visit — not an event comp, should not count.
    Attendance::factory()->create([
        'checked_in_at' => now(),
        'comp_reason_id' => null,
        'entry_covered_by' => EntryCoverageSource::RegularSubscription,
        'entry_coverage' => 25,
    ]);
    // An event comp last week — should not count.
    Attendance::factory()->create([
        'checked_in_at' => now()->subWeeks(2),
        'comp_reason_id' => $presenter->id,
        'entry_covered_by' => EntryCoverageSource::EventComp,
        'entry_coverage' => 999,
    ]);

    Livewire::test(CompCostWidget::class)->assertSuccessful();

    $widget = new CompCostWidget;
    $stats = (fn () => $this->getStats())->call($widget);

    expect($stats)->toHaveCount(2);

    $presenterStat = collect($stats)->first(fn ($stat) => $stat->getLabel() === 'Presenter');
    expect($presenterStat->getValue())->toBe('2 comps')
        ->and($presenterStat->getDescription())->toBe('$40.00 foregone');
});

test('comp cost widget is restricted to manager and up', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($door);
    expect(CompCostWidget::canView())->toBeFalse();

    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);
    expect(CompCostWidget::canView())->toBeTrue();
});

test('register variance widget sums variance across shifts closed this week only', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);

    // $10 over: closed with more in the box than opening_count + cash - drops.
    RegisterShift::factory()->create(['opening_count' => 100, 'closing_count' => 110, 'closed_at' => now()]);
    // Exact.
    RegisterShift::factory()->create(['opening_count' => 50, 'closing_count' => 50, 'closed_at' => now()]);
    // Closed last week — should not count.
    RegisterShift::factory()->create(['opening_count' => 100, 'closing_count' => 999, 'closed_at' => now()->subWeeks(2)]);
    // Still open — should not count.
    RegisterShift::factory()->create(['opening_count' => 100, 'closing_count' => null, 'closed_at' => null]);

    Livewire::test(RegisterVarianceWidget::class)->assertSuccessful();

    $widget = new RegisterVarianceWidget;
    $stats = (fn () => $this->getStats())->call($widget);

    $totalStat = collect($stats)->first(fn ($stat) => $stat->getLabel() === 'Total register variance this week');
    $countStat = collect($stats)->first(fn ($stat) => $stat->getLabel() === 'Shifts with variance');

    expect($totalStat->getValue())->toBe('$10.00')
        ->and($totalStat->getColor())->toBe('warning')
        ->and($countStat->getValue())->toBe('1 of 2');
});

test('register variance widget is restricted to manager and up', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($door);
    expect(RegisterVarianceWidget::canView())->toBeFalse();

    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);
    expect(RegisterVarianceWidget::canView())->toBeTrue();
});

test('register variance widget is hidden once register_shifts_enabled is off, restored once re-enabled', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);

    MembershipSetting::current()->update(['register_shifts_enabled' => false]);
    expect(RegisterVarianceWidget::canView())->toBeFalse();

    MembershipSetting::current()->update(['register_shifts_enabled' => true]);
    expect(RegisterVarianceWidget::canView())->toBeTrue();
});

test('membership composition widget counts active members by category, excluding inactive ones', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);

    $regular = Category::factory()->create(['name' => 'Regular', 'sort_order' => 0]);
    $guest = Category::factory()->create(['name' => 'Guest', 'sort_order' => 1]);

    Member::factory()->create(['category_id' => $regular->id, 'is_active' => true]);
    Member::factory()->create(['category_id' => $regular->id, 'is_active' => true]);
    Member::factory()->create(['category_id' => $guest->id, 'is_active' => true]);
    // Inactive — should not count.
    Member::factory()->create(['category_id' => $regular->id, 'is_active' => false]);

    Livewire::test(MembershipCompositionWidget::class)->assertSuccessful();

    $widget = new MembershipCompositionWidget;
    $stats = (fn () => $this->getStats())->call($widget);

    expect($stats)->toHaveCount(2);

    $regularStat = collect($stats)->first(fn ($stat) => $stat->getLabel() === 'Regular');
    expect($regularStat->getValue())->toBe('2 members');
});

test('membership composition widget is restricted to manager and up', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($door);
    expect(MembershipCompositionWidget::canView())->toBeFalse();

    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);
    expect(MembershipCompositionWidget::canView())->toBeTrue();
});

test('new members chart widget counts members created per month, excluding data outside the 12-month window', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);

    Member::factory()->create(['created_at' => now()]);
    Member::factory()->create(['created_at' => now()]);
    // 13 months ago — outside the 12-month rolling window, should not count.
    Member::factory()->create(['created_at' => now()->startOfMonth()->subMonths(13)]);

    Livewire::test(NewMembersChartWidget::class)->assertSuccessful();

    $widget = new NewMembersChartWidget;
    $data = (fn () => $this->getData())->call($widget);

    expect($data['labels'])->toHaveCount(12)
        ->and(array_sum($data['datasets'][0]['data']))->toBe(2)
        ->and(end($data['datasets'][0]['data']))->toBe(2);
});

test('new members chart widget is restricted to manager and up', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($door);
    expect(NewMembersChartWidget::canView())->toBeFalse();

    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);
    expect(NewMembersChartWidget::canView())->toBeTrue();
});
