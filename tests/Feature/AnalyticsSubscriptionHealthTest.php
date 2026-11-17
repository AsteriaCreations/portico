<?php

use App\Enums\AddOnKind;
use App\Enums\EntryCoverageSource;
use App\Enums\Role;
use App\Filament\Admin\Resources\Members\Pages\ListMembers;
use App\Filament\Admin\Widgets\SubscriberMovementChartWidget;
use App\Filament\Admin\Widgets\SubscriptionHealthWidget;
use App\Filament\Admin\Widgets\SubscriptionUsageWidget;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Analytics\SubscriptionAnalytics;
use App\Support\AnalyticsPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-11-18 12:00:00');

    $this->entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));
});

/**
 * Regular subscriptions for $member covering each of $months ('2026-11').
 */
function subscribed(Member $member, string ...$months): Member
{
    foreach ($months as $month) {
        Subscription::factory()->for($member)->create([
            'add_on_id' => test()->entry->id,
            'covered_month' => $month.'-01',
        ]);
    }

    return $member;
}

function subscriptionStats(string $widget, array $filters = []): array
{
    $instance = Livewire::test($widget, ['pageFilters' => $filters])->assertSuccessful()->instance();

    return collect((fn () => $this->getStats())->call($instance))
        ->keyBy(fn ($stat) => $stat->getLabel())
        ->all();
}

test('a month\'s subscribers split into new, renewed and came back, and the month before\'s into stayed and gone', function () {
    subscribed(Member::factory()->create(), '2026-09', '2026-10');             // renewed in Oct
    subscribed(Member::factory()->create(), '2026-09', '2026-10');             // renewed in Oct
    subscribed(Member::factory()->create(), '2026-09');                        // gone in Oct
    subscribed(Member::factory()->create(), '2026-10');                        // new in Oct
    subscribed(Member::factory()->create(), '2026-06', '2026-10');             // came back in Oct
    // Another plan's subscription is not a Regular subscriber.
    Subscription::factory()->create(['covered_month' => '2026-10-01']);

    expect(app(SubscriptionAnalytics::class)->movement(now()->subMonth()))->toBe([
        'label' => 'Oct 2026',
        'active' => 4,
        'new' => 1,
        'renewed' => 2,
        'returned' => 1,
        'churned' => 1,
        'churn_percent' => 33,
        'in_progress' => false,
    ]);
});

test('the first month anyone subscribed has nothing to be compared with', function () {
    subscribed(Member::factory()->create(), '2026-10');

    $october = app(SubscriptionAnalytics::class)->movement(now()->subMonth());

    expect($october['new'])->toBe(1)
        ->and($october['churned'])->toBe(0)
        ->and($october['churn_percent'])->toBeNull();
});

test('the monthly run ends with the period\'s month and leaves out months still to come', function () {
    subscribed(Member::factory()->create(), '2026-10', '2026-11');

    $months = app(SubscriptionAnalytics::class)->monthly(AnalyticsPeriod::forPreset('this_year'));

    expect(array_column($months, 'label'))->toHaveCount(11)
        ->and(end($months)['label'])->toBe('Nov 2026')
        ->and(end($months)['in_progress'])->toBeTrue()
        ->and(end($months)['renewed'])->toBe(1);

    $data = (fn () => $this->getData())->call(
        Livewire::test(SubscriberMovementChartWidget::class, ['pageFilters' => ['preset' => 'this_week']])->instance(),
    );

    // By month even for a week's period; twelve months ending this one.
    expect($data['labels'])->toHaveCount(12)
        ->and(end($data['labels']))->toBe('Nov 2026')
        ->and(array_column($data['datasets'], 'label'))->toBe(['Renewed', 'Came back', 'New', 'Did not renew'])
        ->and(end($data['datasets'][0]['data']))->toBe(1);
});

test('the health tiles are for the period\'s month, and say when it is still running', function () {
    subscribed(Member::factory()->create(), '2026-10', '2026-11');
    subscribed(Member::factory()->create(), '2026-10');
    subscribed(Member::factory()->create(), '2026-11');

    $thisMonth = subscriptionStats(SubscriptionHealthWidget::class);

    expect($thisMonth['Subscribers']->getValue())->toBe('2')
        ->and($thisMonth['New']->getValue())->toBe('1')
        ->and($thisMonth['Renewed']->getValue())->toBe('1')
        ->and($thisMonth['Not renewed yet']->getValue())->toBe('1')
        ->and($thisMonth['Not renewed yet']->getDescription())->toBe('50% of the month before\'s subscribers');

    $lastMonth = subscriptionStats(SubscriptionHealthWidget::class, ['preset' => 'last_month']);

    expect($lastMonth['Subscribers']->getValue())->toBe('2')
        ->and($lastMonth)->toHaveKey('Did not renew')
        ->and($lastMonth['Did not renew']->getDescription())->toBe('Nobody subscribed the month before');
});

test('the people who could subscribe and haven\'t are counted, and the Members filter lists the same people', function () {
    MembershipSetting::current()->update(['subscription_eligibility_threshold' => 5]);

    $eligible = Member::factory()->create(['subscription_eligible' => true]);
    $subscribedNow = subscribed(Member::factory()->create(['subscription_eligible' => true]), '2026-11');
    // Subscribed last month only: eligible and not subscribed this month.
    $lapsedSubscriber = subscribed(Member::factory()->create(['subscription_eligible' => true]), '2026-10');
    $notEligible = Member::factory()->create();
    $inactive = Member::factory()->create(['subscription_eligible' => true, 'is_active' => false]);

    $stat = subscriptionStats(SubscriptionHealthWidget::class)['Can subscribe, not subscribed'];

    expect($stat->getValue())->toBe('2')
        ->and(urldecode($stat->getUrl()))->toContain('tableFilters[eligible_not_subscribed][isActive]=1');

    Livewire::test(ListMembers::class)
        ->filterTable('eligible_not_subscribed')
        ->assertCanSeeTableRecords([$eligible, $lapsedSubscriber])
        ->assertCanNotSeeTableRecords([$subscribedNow, $notEligible, $inactive]);
});

test('usage is the month\'s subscription-covered visits, who never came, and the fees covered against the price', function () {
    Plan::factory()->create(['add_on_id' => $this->entry->id, 'duration_months' => 1, 'price' => 60, 'credit' => 25, 'effective_from' => '2026-01-01', 'effective_to' => null]);

    $regular = subscribed(Member::factory()->create(), '2026-11');
    $occasional = subscribed(Member::factory()->create(), '2026-11');
    subscribed(Member::factory()->create(), '2026-11'); // never came

    $visit = fn (Member $member, string $day, array $attributes = []) => Attendance::factory()
        ->for($member)
        ->for(Event::factory()->create(['event_date' => $day]))
        ->create(['checked_in_at' => $day.' 20:00:00', ...$attributes]);

    $covered = ['entry_covered_by' => EntryCoverageSource::RegularSubscription, 'entry_coverage' => 25];
    $visit($regular, '2026-11-07', $covered);
    $visit($regular, '2026-11-14', $covered);
    $visit($occasional, '2026-11-07', $covered);
    // Paid at the door by someone without a subscription: not subscription use.
    $visit(Member::factory()->create(), '2026-11-07', ['entry_covered_by' => EntryCoverageSource::None]);
    // Covered, but last month.
    $visit($regular, '2026-10-10', $covered);

    expect(app(SubscriptionAnalytics::class)->usage(now()))->toBe([
        'subscribers' => 3,
        'visits' => 3,
        'no_visit' => 1,
        'coverage_cents' => 7500,
        'average_coverage_cents' => 2500,
        'price_cents' => 6000,
    ]);

    $stats = subscriptionStats(SubscriptionUsageWidget::class);

    expect($stats['Visits on the subscription']->getDescription())->toBe('1.0 a subscriber')
        ->and($stats['Subscribers who didn\'t visit']->getDescription())->toBe('33% of subscribers')
        ->and($stats['Entry fees covered, per subscriber']->getValue())->toBe('$25.00')
        ->and($stats['Entry fees covered, per subscriber']->getDescription())->toBe('A month costs $60.00 · $75.00 covered in all');
});

test('a month nobody subscribed in shows a single plain tile', function () {
    $stats = subscriptionStats(SubscriptionUsageWidget::class);

    expect($stats)->toHaveCount(1)
        ->and($stats['Subscribers']->getDescription())->toBe('Nobody subscribed that month');
});

test('subscriptions have their own tab', function () {
    $this->get('/admin/analytics?tab=subscriptions')
        ->assertSuccessful()
        ->assertSee('Active Regular subscribers')
        ->assertSee('Regular subscribers — Nov 2026')
        ->assertSee('Subscribers month by month')
        ->assertSee('Use of the subscription — Nov 2026');

    $this->get('/admin/analytics?tab=revenue')
        ->assertSuccessful()
        ->assertDontSee('Active Regular subscribers');
});
