<?php

use App\Enums\AddOnKind;
use App\Enums\Role;
use App\Filament\Admin\Widgets\CohortRetentionWidget;
use App\Filament\Admin\Widgets\FirstVisitReturnWidget;
use App\Filament\Admin\Widgets\GuestConversionWidget;
use App\Filament\Admin\Widgets\LapsedMembersWidget;
use App\Filament\Admin\Widgets\MemberFunnelWidget;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Analytics\RetentionAnalytics;
use App\Support\AnalyticsPeriod;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-11-18 12:00:00');

    $this->entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));
});

/**
 * Arrived visits by $member on each of $days, each on its own event.
 */
function retentionVisits(Member $member, string ...$days): Member
{
    foreach ($days as $day) {
        Attendance::factory()
            ->for($member)
            ->for(Event::factory()->create(['event_date' => $day]))
            ->create(['checked_in_at' => $day.' 20:00:00']);
    }

    return $member;
}

function retentionStats(string $widget, array $filters = []): array
{
    $instance = Livewire::test($widget, ['pageFilters' => $filters])->assertSuccessful()->instance();

    return collect((fn () => $this->getStats())->call($instance))
        ->keyBy(fn ($stat) => $stat->getLabel())
        ->all();
}

test('first-timers are judged on coming back only once their window has passed', function () {
    // First visits in September: all three windows are long past.
    retentionVisits(Member::factory()->create(), '2026-09-05', '2026-09-20');       // back in 15 days
    retentionVisits(Member::factory()->create(), '2026-09-05', '2026-10-25');       // back in 50 days
    retentionVisits(Member::factory()->create(), '2026-09-05');                     // never back
    // Two events on the same night is not coming back.
    retentionVisits(Member::factory()->create(), '2026-09-12', '2026-09-12');
    // A regular from before: not a first-timer in September at all.
    retentionVisits(Member::factory()->create(), '2026-08-01', '2026-09-05');

    $september = AnalyticsPeriod::fromFilters(['preset' => 'custom', 'from' => '2026-09-01', 'until' => '2026-09-30']);

    expect(app(RetentionAnalytics::class)->returnRates($september))->toBe([
        'first_timers' => 4,
        'windows' => [
            30 => ['eligible' => 4, 'returned' => 1],
            60 => ['eligible' => 4, 'returned' => 2],
            90 => ['eligible' => 0, 'returned' => 0],
        ],
    ]);

    $stats = retentionStats(FirstVisitReturnWidget::class, ['preset' => 'custom', 'from' => '2026-09-01', 'until' => '2026-09-30']);

    expect($stats['Back within 30 days']->getValue())->toBe('25%')
        ->and($stats['Back within 30 days']->getDescription())->toBe('1 of 4 whose 30 days are up')
        ->and($stats['Back within 60 days']->getValue())->toBe('50%')
        // 5 Sep + 90 days is 4 Dec: nobody's 90 days are up on 18 Nov.
        ->and($stats['Back within 90 days']->getValue())->toBe('—')
        ->and($stats['Back within 90 days']->getDescription())->toBe('Too early to say');
});

test('someone who came back before their window closed is still not counted early', function () {
    // First visit 10 Nov, back on the 17th; 30 days aren't up until 10 Dec.
    retentionVisits(Member::factory()->create(), '2026-11-10', '2026-11-17');

    $rates = app(RetentionAnalytics::class)->returnRates(AnalyticsPeriod::forPreset('this_month'));

    expect($rates['first_timers'])->toBe(1)
        ->and($rates['windows'][30])->toBe(['eligible' => 0, 'returned' => 0]);
});

test('the cohort grid shares out each month\'s newcomers across the months after', function () {
    // September's newcomers: four people.
    retentionVisits(Member::factory()->create(), '2026-09-05', '2026-10-10', '2026-11-07');
    retentionVisits(Member::factory()->create(), '2026-09-05', '2026-10-17');
    retentionVisits(Member::factory()->create(), '2026-09-12', '2026-09-19'); // back only within September
    retentionVisits(Member::factory()->create(), '2026-09-26');
    // October's newcomer.
    retentionVisits(Member::factory()->create(), '2026-10-03', '2026-11-14');
    // Came before these records began: in nobody's cohort.
    retentionVisits(Member::factory()->create(['prior_visits' => 2]), '2026-09-05', '2026-10-10');

    $rows = collect(app(RetentionAnalytics::class)->cohorts(AnalyticsPeriod::forPreset('this_month'), 3))->keyBy('label');

    expect($rows)->toHaveCount(12)
        ->and($rows->keys()->last())->toBe('Nov 2026')
        ->and($rows['Sep 2026'])->toBe(['label' => 'Sep 2026', 'size' => 4, 'retained' => [1 => 50, 2 => 25, 3 => null]])
        ->and($rows['Oct 2026'])->toBe(['label' => 'Oct 2026', 'size' => 1, 'retained' => [1 => 100, 2 => null, 3 => null]])
        // Nobody new in August: nothing to take a share of.
        ->and($rows['Aug 2026']['size'])->toBe(0)
        ->and($rows['Aug 2026']['retained'][1])->toBeNull();

    Livewire::test(CohortRetentionWidget::class, ['pageFilters' => ['preset' => 'this_month']])
        ->assertSee('Who keeps coming back')
        ->assertSee('<th scope="row" class="py-1 text-start font-normal">Sep 2026</th>', false)
        ->assertSee('2 months later')
        ->assertSee('50%');
});

test('a cohort month still to come is left out of the grid', function () {
    $rows = app(RetentionAnalytics::class)->cohorts(AnalyticsPeriod::forPreset('this_year'));

    expect(collect($rows)->pluck('label')->last())->toBe('Nov 2026')
        ->and($rows)->toHaveCount(11);
});

test('guests are followed from their first visit: who came back, who is no longer a Guest', function () {
    $guestCategory = Category::firstOrCreate(['name' => 'Guest']);
    $irregular = Category::firstOrCreate(['name' => 'Irregular']);
    $sponsor = Member::factory()->create();

    retentionVisits(Member::factory()->create(['sponsor_id' => $sponsor->id, 'category_id' => $guestCategory->id]), '2026-11-07');
    retentionVisits(Member::factory()->create(['sponsor_id' => $sponsor->id, 'category_id' => $guestCategory->id]), '2026-11-07', '2026-11-14');
    retentionVisits(Member::factory()->create(['sponsor_id' => $sponsor->id, 'category_id' => $irregular->id]), '2026-11-07', '2026-11-14');
    // Nobody's guest.
    retentionVisits(Member::factory()->create(['category_id' => $irregular->id]), '2026-11-07');
    // A guest whose first visit was last month.
    retentionVisits(Member::factory()->create(['sponsor_id' => $sponsor->id, 'category_id' => $guestCategory->id]), '2026-10-10');

    expect(app(RetentionAnalytics::class)->guestConversion(AnalyticsPeriod::forPreset('this_month')))
        ->toBe(['guests' => 3, 'returned' => 2, 'moved_on' => 1]);

    $stats = retentionStats(GuestConversionWidget::class, ['preset' => 'this_month']);

    expect($stats['Came back']->getValue())->toBe(2)
        ->and($stats['Came back']->getDescription())->toBe('67% of those guests');

    MembershipSetting::current()->update(['guests_enabled' => false]);
    expect(GuestConversionWidget::canView())->toBeFalse();
});

test('the eligibility scope agrees with isSubscriptionEligible(), with and without a window', function () {
    MembershipSetting::current()->update(['subscription_eligibility_threshold' => 2, 'subscription_eligibility_window_months' => null]);

    $flagged = Member::factory()->create(['subscription_eligible' => true]);
    $twoVisits = retentionVisits(Member::factory()->create(), '2026-03-01', '2026-11-07');
    $oneVisitOnePrior = retentionVisits(Member::factory()->create(['prior_visits' => 1]), '2026-11-07');
    $oneVisit = retentionVisits(Member::factory()->create(), '2026-11-07');
    // A prepay that never arrived doesn't count.
    $prepaid = retentionVisits(Member::factory()->create(), '2026-11-07');
    Attendance::factory()->for($prepaid)->for(Event::factory()->create(['event_date' => '2026-11-20']))->create(['checked_in_at' => null]);

    $everyone = [$flagged, $twoVisits, $oneVisitOnePrior, $oneVisit, $prepaid];
    $agree = function () use ($everyone): array {
        MembershipSetting::forgetCurrent();
        $scoped = Member::query()->subscriptionEligible()->pluck('id')->all();

        foreach ($everyone as $member) {
            expect(in_array($member->id, $scoped, true))->toBe($member->fresh()->isSubscriptionEligible());
        }

        return $scoped;
    };

    expect($agree())->toEqualCanonicalizing([$flagged->id, $twoVisits->id, $oneVisitOnePrior->id]);

    // A six-month window: March no longer counts, and neither do prior visits.
    MembershipSetting::current()->update(['subscription_eligibility_window_months' => 6]);

    expect($agree())->toEqualCanonicalizing([$flagged->id]);
});

test('the funnel counts active members at each step today', function () {
    MembershipSetting::current()->update(['subscription_eligibility_threshold' => 2]);

    Member::factory()->create();                                            // on record, never been
    retentionVisits(Member::factory()->create(), '2026-11-07');             // visited
    $eligible = retentionVisits(Member::factory()->create(), '2026-10-03', '2026-11-07');
    $subscriber = retentionVisits(Member::factory()->create(), '2026-10-03', '2026-11-07');
    Subscription::factory()->for($subscriber)->create(['add_on_id' => $this->entry->id, 'covered_month' => '2026-11-01']);
    // Last month's subscription isn't "this month".
    Subscription::factory()->for($eligible)->create(['add_on_id' => $this->entry->id, 'covered_month' => '2026-10-01']);
    // Inactive: out of every step.
    retentionVisits(Member::factory()->create(['is_active' => false]), '2026-10-03', '2026-11-07');

    expect(app(RetentionAnalytics::class)->funnel())->toBe(['members' => 4, 'visited' => 3, 'eligible' => 2, 'subscribed' => 1]);

    $stats = retentionStats(MemberFunnelWidget::class);

    expect($stats['Can subscribe']->getValue())->toBe('2')
        ->and($stats['Can subscribe']->getDescription())->toBe('50% of active members');
});

test('the lapsed list is regulars with no recent visit, never banned, deceased or inactive members', function () {
    $lapsed = retentionVisits(Member::factory()->create(), '2026-03-01', '2026-04-01', '2026-05-01');
    // Two visits here plus one from before the records began: still a regular.
    $lapsedWithPrior = retentionVisits(Member::factory()->create(['prior_visits' => 1]), '2026-03-01', '2026-04-01');
    $stillComing = retentionVisits(Member::factory()->create(), '2026-03-01', '2026-04-01', '2026-11-07');
    $tooFewVisits = retentionVisits(Member::factory()->create(), '2026-03-01', '2026-04-01');
    $banned = retentionVisits(Member::factory()->create(['is_banned' => true]), '2026-03-01', '2026-04-01', '2026-05-01');
    $deceased = retentionVisits(Member::factory()->create(['is_deceased' => true]), '2026-03-01', '2026-04-01', '2026-05-01');
    $inactive = retentionVisits(Member::factory()->create(['is_active' => false]), '2026-03-01', '2026-04-01', '2026-05-01');
    // A suspension that has run out no longer keeps someone off the list.
    $suspensionOver = retentionVisits(Member::factory()->create(['is_banned' => true, 'banned_until' => '2026-06-01']), '2026-03-01', '2026-04-01', '2026-05-01');

    Livewire::test(LapsedMembersWidget::class)
        ->assertCanSeeTableRecords([$lapsed, $lapsedWithPrior, $suspensionOver])
        ->assertCanNotSeeTableRecords([$stillComing, $tooFewVisits, $banned, $deceased, $inactive])
        ->assertSee('At least 3 visits, none in 90 days')
        // Loosen "regular" to two visits: the two-visit member now shows.
        ->filterTable('lapsed', ['min_visits' => 2, 'days' => 90])
        ->assertCanSeeTableRecords([$lapsed, $tooFewVisits])
        // And tighten "lately" to a year: nobody has been away that long.
        ->filterTable('lapsed', ['min_visits' => 2, 'days' => 365])
        ->assertCanNotSeeTableRecords([$lapsed, $tooFewVisits, $lapsedWithPrior]);
});

test('the lapsed list exports without contact details', function () {
    $category = Category::factory()->create(['name' => 'Regular']);
    $member = retentionVisits(
        Member::factory()->create(['username' => 'jsmith', 'preferred_name' => 'Jay', 'email' => 'jay@example.com', 'category_id' => $category->id]),
        '2026-03-01', '2026-04-01', '2026-05-01',
    );

    $response = Livewire::test(LapsedMembersWidget::class)
        ->callAction(TestAction::make('export')->table())
        ->assertFileDownloaded('lapsed-members-2026-11-18.csv');

    $csv = base64_decode($response->effects['download']['content']);

    expect($csv)->toContain('"Member Number",Username,"Preferred Name",Category,Visits,"Last Visit","Days Since"')
        ->toContain($member->member_number.',jsmith,Jay,Regular,3,2026-05-01,201')
        ->not->toContain('jay@example.com');
});

test('the Members tab carries the retention widgets', function () {
    $this->get('/admin/analytics?tab=members')
        ->assertSuccessful()
        ->assertSee('From record to subscriber — today')
        ->assertSee('Do first-timers come back? — This week')
        ->assertSee('Guests — This week')
        ->assertSee('Who keeps coming back')
        ->assertSee('Regulars who have stopped coming');
});
