<?php

namespace App\Services\Analytics;

use App\Models\AddOn;
use App\Models\Member;
use App\Support\AnalyticsPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Whether people come back, for the Analytics page. Everything hangs off a
 * member's first visit (Member::scopeFirstVisitedBetween()), and a "return"
 * is an arrival on a later day: a second check-in the same night, to a
 * second event running alongside, is not coming back.
 */
class RetentionAnalytics
{
    /** Days after a first visit within which coming back is measured. */
    public const RETURN_WINDOWS = [30, 60, 90];

    public const LAPSED_MIN_VISITS = 3;

    public const LAPSED_DAYS = 90;

    /**
     * Of the people whose first visit fell in the period, how many came back
     * within each window. Only people whose window has fully passed are
     * judged ("eligible"): someone who first came last week hasn't failed to
     * return within 30 days yet.
     *
     * @return array{first_timers: int, windows: array<int, array{eligible: int, returned: int}>}
     */
    public function returnRates(AnalyticsPeriod $period): array
    {
        $firstVisits = $this->firstVisits(Member::query()->firstVisitedBetween($period->from, $period->untilExclusive));
        $today = CarbonImmutable::now()->startOfDay();
        $windows = [];

        foreach (self::RETURN_WINDOWS as $days) {
            $eligible = $firstVisits->filter(fn (array $visit): bool => $visit['first_day']->addDays($days)->lessThanOrEqualTo($today));

            $windows[$days] = [
                'eligible' => $eligible->count(),
                'returned' => $eligible->filter(fn (array $visit): bool => $visit['return_day'] !== null
                    && $visit['first_day']->diffInDays($visit['return_day']) <= $days)->count(),
            ];
        }

        return ['first_timers' => $firstVisits->count(), 'windows' => $windows];
    }

    /**
     * Month-by-month retention: each row is the people whose first visit
     * fell in one month (the twelve ending with the period's last month),
     * and each cell the share of them who visited in the 1st, 2nd, ... month
     * after it. A cell is null when that month hasn't begun, and a cohort
     * month still to come is left out.
     *
     * @return list<array{label: string, size: int, retained: array<int, ?int>}>
     */
    public function cohorts(AnalyticsPeriod $period, int $monthsAfter = 6): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $months = array_values(array_filter(
            $period->buckets(12, AnalyticsPeriod::GRAIN_MONTH),
            fn (AnalyticsPeriod $month): bool => $month->from->lessThanOrEqualTo($today),
        ));

        if ($months === []) {
            return [];
        }

        $members = Member::query()->firstVisitedBetween($months[0]->from, end($months)->untilExclusive);
        // member id => index of its cohort month
        $cohortOf = $this->firstVisits($members)
            ->map(fn (array $visit): int => (int) round($months[0]->from->diffInMonths($visit['first_day']->startOfMonth())))
            ->all();

        // [cohort index][months after][member id] => true
        $seen = [];

        DB::table('attendance')
            ->whereIn('member_id', (clone $members)->select('members.id'))
            ->where('checked_in_at', '>=', $months[0]->from)
            ->orderBy('id')
            ->select(['id', 'member_id', 'checked_in_at'])
            ->chunkById(2000, function (Collection $visits) use (&$seen, $cohortOf, $months): void {
                foreach ($visits as $visit) {
                    $cohort = $cohortOf[$visit->member_id];
                    $after = (int) round($months[$cohort]->from->diffInMonths(CarbonImmutable::parse($visit->checked_in_at)->startOfMonth()));
                    $seen[$cohort][$after][$visit->member_id] = true;
                }
            });

        $sizes = array_count_values($cohortOf);
        $rows = [];

        foreach ($months as $index => $month) {
            $size = $sizes[$index] ?? 0;
            $retained = [];

            for ($after = 1; $after <= $monthsAfter; $after++) {
                $retained[$after] = match (true) {
                    $month->from->addMonths($after)->greaterThan($today) => null,
                    $size === 0 => null,
                    default => (int) round(count($seen[$index][$after] ?? []) * 100 / $size),
                };
            }

            $rows[] = ['label' => $month->label(), 'size' => $size, 'retained' => $retained];
        }

        return $rows;
    }

    /**
     * People who came as someone's guest (members.sponsor_id) and first
     * visited in the period: how many came back on a later day, and how
     * many are no longer in the Guest category.
     *
     * @return array{guests: int, returned: int, moved_on: int}
     */
    public function guestConversion(AnalyticsPeriod $period): array
    {
        $guests = Member::query()
            ->firstVisitedBetween($period->from, $period->untilExclusive)
            ->whereNotNull('sponsor_id');

        return [
            'guests' => (clone $guests)->count(),
            'returned' => $this->firstVisits($guests)->filter(fn (array $visit): bool => $visit['return_day'] !== null)->count(),
            'moved_on' => (clone $guests)->whereDoesntHave('category', fn (Builder $category) => $category->where('name', 'Guest'))->count(),
        ];
    }

    /**
     * Where the active membership stands today on the way from signing up
     * to subscribing. Each step is counted on its own, so a later step is
     * usually, but not necessarily, a part of the one before.
     *
     * @return array{members: int, visited: int, eligible: int, subscribed: int}
     */
    public function funnel(): array
    {
        $active = fn (): Builder => Member::query()->where('is_active', true)->where('is_deceased', false);
        $thisMonth = now()->startOfMonth()->toDateString();

        return [
            'members' => $active()->count(),
            'visited' => $active()->where(fn (Builder $visited) => $visited
                ->where('prior_visits', '>', 0)
                ->orWhereHas('attendance', fn (Builder $attendance) => $attendance->whereNotNull('checked_in_at')))->count(),
            'eligible' => $active()->subscriptionEligible()->count(),
            'subscribed' => $active()->whereHas('subscriptions', fn (Builder $subscription) => $subscription
                ->where('add_on_id', AddOn::entry()->id)
                ->whereDate('covered_month', $thisMonth))->count(),
        ];
    }

    /**
     * Members a lapsed list could contain: active, alive, not currently
     * banned, and seen at least once, each loaded with visits (arrivals) and
     * last_visit_at. lapsed() then narrows it.
     *
     * @return Builder<Member>
     */
    public function lapsedCandidates(): Builder
    {
        return Member::query()
            ->with('category')
            ->where('is_active', true)
            ->where('is_deceased', false)
            // Not currently banned: see Member::isCurrentlyBanned().
            ->where(fn (Builder $query) => $query
                ->where('is_banned', false)
                ->orWhere(fn (Builder $expired) => $expired->whereNotNull('banned_until')->whereDate('banned_until', '<=', today())))
            ->whereHas('attendance', fn (Builder $attendance) => $attendance->whereNotNull('checked_in_at'))
            ->withCount(['attendance as visits' => fn (Builder $attendance) => $attendance->whereNotNull('checked_in_at')])
            ->withMax('attendance as last_visit_at', 'checked_in_at');
    }

    /**
     * Regulars who have stopped coming: at least $minVisits visits ever
     * (prior_visits included) and none in the last $days days.
     *
     * @param  Builder<Member>  $query  from lapsedCandidates()
     * @return Builder<Member>
     */
    public function lapsed(Builder $query, int $minVisits = self::LAPSED_MIN_VISITS, int $days = self::LAPSED_DAYS): Builder
    {
        return $query
            ->whereDoesntHave('attendance', fn (Builder $attendance) => $attendance->where('checked_in_at', '>=', today()->subDays($days)))
            ->whereRaw(
                '(select count(*) from attendance where attendance.member_id = members.id and attendance.checked_in_at is not null) + members.prior_visits >= ?',
                [$minVisits],
            );
    }

    /**
     * For each of $members (already limited to a first-visit span): the day
     * of the first visit and the day of the next one, if any.
     *
     * @param  Builder<Member>  $members
     * @return Collection<int, array{first_day: CarbonImmutable, return_day: ?CarbonImmutable}> keyed by member id
     */
    private function firstVisits(Builder $members): Collection
    {
        // member id => visit days, oldest first
        $days = [];

        DB::table('attendance')
            ->whereIn('member_id', (clone $members)->select('members.id'))
            ->whereNotNull('checked_in_at')
            ->orderBy('id')
            ->select(['id', 'member_id', 'checked_in_at'])
            ->chunkById(2000, function (Collection $visits) use (&$days): void {
                foreach ($visits as $visit) {
                    $days[$visit->member_id][] = substr((string) $visit->checked_in_at, 0, 10);
                }
            });

        return collect($days)->map(function (array $visitDays): array {
            $visitDays = array_values(array_unique($visitDays));
            sort($visitDays);

            return [
                'first_day' => CarbonImmutable::parse($visitDays[0]),
                'return_day' => isset($visitDays[1]) ? CarbonImmutable::parse($visitDays[1]) : null,
            ];
        });
    }
}
