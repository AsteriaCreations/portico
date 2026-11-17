<?php

namespace App\Services\Analytics;

use App\Enums\EntryCoverageSource;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\Member;
use App\Models\Plan;
use App\Support\AnalyticsPeriod;
use App\Support\Cents;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The health of the Regular (entry) subscription, for the Analytics page:
 * who joined, stayed, came back and dropped off month by month, and how much
 * use subscribers get out of it. A subscription covers one calendar month,
 * so everything here is by month, and a comped subscription (a manager
 * perk, say) is a subscriber like any other.
 */
class SubscriptionAnalytics
{
    /** @var array<string, array<int, true>>|null 'Y-m' => [member id => true] */
    private ?array $subscribersByMonth = null;

    /**
     * The twelve months ending with the period's month, oldest first; a
     * month still to come is left out.
     *
     * @return list<array{label: string, active: int, new: int, renewed: int, returned: int, churned: int, churn_percent: ?int, in_progress: bool}>
     */
    public function monthly(AnalyticsPeriod $period, int $months = 12): array
    {
        $today = CarbonImmutable::now();

        return collect($period->buckets($months, AnalyticsPeriod::GRAIN_MONTH))
            ->filter(fn (AnalyticsPeriod $month): bool => $month->from->lessThanOrEqualTo($today))
            ->map(fn (AnalyticsPeriod $month): array => $this->movement($month->from))
            ->values()
            ->all();
    }

    /**
     * One month's subscribers against the month before:
     *  - new: never subscribed before this month
     *  - renewed: also subscribed the month before
     *  - returned: subscribed some earlier month, but not the one before
     *  - churned: subscribed the month before and not this one
     * new + renewed + returned is everyone active. While the month is still
     * running (in_progress), "churned" really means "not renewed yet".
     *
     * @return array{label: string, active: int, new: int, renewed: int, returned: int, churned: int, churn_percent: ?int, in_progress: bool}
     */
    public function movement(CarbonInterface $month): array
    {
        $month = CarbonImmutable::instance($month)->startOfMonth();
        $byMonth = $this->subscribersByMonth();

        $current = $byMonth[$month->format('Y-m')] ?? [];
        $previous = $byMonth[$month->subMonth()->format('Y-m')] ?? [];

        $earlier = [];
        foreach ($byMonth as $key => $members) {
            if ($key < $month->format('Y-m')) {
                $earlier += $members;
            }
        }

        $renewed = count(array_intersect_key($current, $previous));
        $new = count(array_diff_key($current, $earlier));
        $churned = count(array_diff_key($previous, $current));

        return [
            'label' => $month->translatedFormat('M Y'),
            'active' => count($current),
            'new' => $new,
            'renewed' => $renewed,
            'returned' => count($current) - $renewed - $new,
            'churned' => $churned,
            'churn_percent' => $previous === [] ? null : (int) round($churned * 100 / count($previous)),
            'in_progress' => $month->isSameMonth(CarbonImmutable::now()),
        ];
    }

    /**
     * What a month's subscribers got out of it: their subscription-covered
     * visits, how many never came, and the entry fees the subscription
     * covered, next to what one month cost at the time.
     *
     * @return array{subscribers: int, visits: int, no_visit: int, coverage_cents: int, average_coverage_cents: ?int, price_cents: ?int}
     */
    public function usage(CarbonInterface $month): array
    {
        $month = CarbonImmutable::instance($month)->startOfMonth();
        $subscribers = array_keys($this->subscribersByMonth()[$month->format('Y-m')] ?? []);

        $covered = Attendance::query()
            ->where('entry_covered_by', EntryCoverageSource::RegularSubscription)
            ->where('checked_in_at', '>=', $month)
            ->where('checked_in_at', '<', $month->addMonth());

        $visited = Attendance::query()
            ->whereIn('member_id', $subscribers)
            ->where('checked_in_at', '>=', $month)
            ->where('checked_in_at', '<', $month->addMonth())
            ->distinct('member_id')
            ->count('member_id');

        $coverage = Cents::of((clone $covered)->sum('entry_coverage'));
        $price = Plan::currentFor(AddOn::entry(), $month)?->price;

        return [
            'subscribers' => count($subscribers),
            'visits' => (clone $covered)->count(),
            'no_visit' => count($subscribers) - $visited,
            'coverage_cents' => $coverage,
            'average_coverage_cents' => $subscribers === [] ? null : (int) round($coverage / count($subscribers)),
            'price_cents' => $price === null ? null : Cents::of($price),
        ];
    }

    /**
     * Narrows a members query to people who could subscribe and haven't for
     * the current month: active, alive, eligible (Member::
     * scopeSubscriptionEligible()), and holding no Regular subscription for
     * this month.
     *
     * @param  Builder<Member>  $members
     * @return Builder<Member>
     */
    public function eligibleNotSubscribed(Builder $members): Builder
    {
        return $members
            ->where('is_active', true)
            ->where('is_deceased', false)
            ->subscriptionEligible()
            ->whereDoesntHave('subscriptions', fn (Builder $subscription) => $subscription
                ->where('add_on_id', AddOn::entry()->id)
                ->whereDate('covered_month', now()->startOfMonth()->toDateString()));
    }

    /**
     * Every Regular subscription ever, as month => members. One query for
     * the lot: "new" needs the whole history anyway.
     *
     * @return array<string, array<int, true>>
     */
    private function subscribersByMonth(): array
    {
        if ($this->subscribersByMonth !== null) {
            return $this->subscribersByMonth;
        }

        $byMonth = [];

        DB::table('subscriptions')
            ->where('add_on_id', AddOn::entry()->id)
            ->orderBy('id')
            ->select(['id', 'member_id', 'covered_month'])
            ->chunkById(5000, function ($subscriptions) use (&$byMonth): void {
                foreach ($subscriptions as $subscription) {
                    $byMonth[substr((string) $subscription->covered_month, 0, 7)][$subscription->member_id] = true;
                }
            });

        ksort($byMonth);

        return $this->subscribersByMonth = $byMonth;
    }
}
