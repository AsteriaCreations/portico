<?php

namespace App\Services\Analytics;

use App\Enums\EntryCoverageSource;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Services\ShowrunnerPayoutService;
use App\Support\AnalyticsPeriod;
use App\Support\Cents;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * One event against another, for the Analytics page. Only events that have
 * happened (dated today or earlier), and never a private rental: that is a
 * booking, not a night the club ran.
 */
class EventAnalytics
{
    /**
     * The period's events, each loaded with the counts figures() reads, so
     * a table can sort and page them in SQL.
     *
     * @return Builder<Event>
     */
    public function comparisonQuery(AnalyticsPeriod $period): Builder
    {
        return $period->constrainDate($this->heldEvents(), 'event_date')
            ->with('eventType')
            ->withCount([
                'attendance as no_shows' => fn (Builder $query) => $query->whereNull('checked_in_at'),
                'attendance as comps' => fn (Builder $query) => $query
                    ->whereNotNull('checked_in_at')
                    ->where('entry_covered_by', EntryCoverageSource::EventComp),
                'attendance as subscribers' => fn (Builder $query) => $query
                    ->whereNotNull('checked_in_at')
                    ->whereIn('entry_covered_by', ShowrunnerPayoutService::SUBSCRIPTION_SOURCES),
                // The Member::scopeFirstVisitedBetween() rule, per visit: no
                // visits from before these records and no earlier arrival.
                'attendance as first_timers' => fn (Builder $query) => $query
                    ->whereNotNull('checked_in_at')
                    ->whereHas('member', fn (Builder $member) => $member->where('prior_visits', 0))
                    ->whereNotExists(fn (QueryBuilder $earlier) => $earlier
                        ->from('attendance as earlier')
                        ->whereColumn('earlier.member_id', 'attendance.member_id')
                        ->whereColumn('earlier.checked_in_at', '<', 'attendance.checked_in_at')),
            ])
            // Everything paid towards the event, a prepay that never arrived
            // included: the same figure as EventSummaryService.
            ->withSum('attendance as revenue', 'amount_paid');
    }

    /**
     * One comparison row's numbers, from an event loaded by comparisonQuery().
     *
     * @param  array<int, int>  $usualHeadcounts  usualHeadcounts(), so it is worked out once per table
     * @return array{headcount: int, no_shows: int, revenue_cents: int, per_head_cents: ?int, comps: int, comp_percent: ?int, subscribers: int, subscriber_percent: ?int, first_timers: int, usual_headcount: ?int}
     */
    public function figures(Event $event, array $usualHeadcounts = []): array
    {
        $headcount = (int) $event->headcount;
        $revenue = Cents::of($event->revenue);
        $share = fn (int $part): ?int => $headcount > 0 ? (int) round($part * 100 / $headcount) : null;

        return [
            'headcount' => $headcount,
            'no_shows' => (int) $event->no_shows,
            'revenue_cents' => $revenue,
            'per_head_cents' => $headcount > 0 ? (int) round($revenue / $headcount) : null,
            'comps' => (int) $event->comps,
            'comp_percent' => $share((int) $event->comps),
            'subscribers' => (int) $event->subscribers,
            'subscriber_percent' => $share((int) $event->subscribers),
            'first_timers' => (int) $event->first_timers,
            'usual_headcount' => $usualHeadcounts[$event->event_type_id] ?? null,
        ];
    }

    /**
     * The usual headcount for each event type: its average over the twelve
     * months up to the end of the period, so one night can be read against
     * what that kind of night normally draws.
     *
     * @return array<int, int> event_type_id => average headcount, rounded
     */
    public function usualHeadcounts(AnalyticsPeriod $period): array
    {
        $until = CarbonImmutable::instance($period->untilExclusive);

        return $this->heldEvents()
            ->whereDate('event_date', '>=', $until->subYear()->toDateString())
            ->whereDate('event_date', '<', $until->toDateString())
            ->whereNotNull('event_type_id')
            ->get()
            ->groupBy('event_type_id')
            ->map(fn (Collection $events): int => (int) round($events->avg('headcount')))
            ->all();
    }

    /**
     * The period's events rolled up by type, in the types' own order.
     *
     * @return Collection<int, array{name: string, events: int, average_headcount: int, average_revenue_cents: int, per_head_cents: ?int}>
     */
    public function byEventType(AnalyticsPeriod $period): Collection
    {
        return $this->rollUpQuery($period)->with('eventType')->get()
            ->sortBy(fn (Event $event): int => $event->eventType?->sort_order ?? PHP_INT_MAX)
            ->groupBy(fn (Event $event): string => $event->eventType?->name ?? __('No type'))
            ->map($this->rollUp(...))
            ->values();
    }

    /**
     * The period's events rolled up by the weekday they were held on,
     * starting from the club's first day of the week.
     *
     * @return Collection<int, array{name: string, events: int, average_headcount: int, average_revenue_cents: int, per_head_cents: ?int}>
     */
    public function byWeekday(AnalyticsPeriod $period): Collection
    {
        $firstDay = MembershipSetting::startOfWeek()->dayOfWeek;

        return $this->rollUpQuery($period)->get()
            ->sortBy(fn (Event $event): int => ($event->event_date->dayOfWeek - $firstDay + 7) % 7)
            ->groupBy(fn (Event $event): string => $event->event_date->isoFormat('dddd'))
            ->map($this->rollUp(...))
            ->values();
    }

    /**
     * The period's events with just what rollUp() reads: the comparison
     * query's other counts (first-timers above all) cost a subquery per visit.
     *
     * @return Builder<Event>
     */
    private function rollUpQuery(AnalyticsPeriod $period): Builder
    {
        return $period->constrainDate($this->heldEvents(), 'event_date')
            ->withSum('attendance as revenue', 'amount_paid');
    }

    /**
     * @param  Collection<int, Event>  $events
     * @return array{name: string, events: int, average_headcount: int, average_revenue_cents: int, per_head_cents: ?int}
     */
    private function rollUp(Collection $events, string $name): array
    {
        $headcount = (int) $events->sum('headcount');
        $revenue = $events->sum(fn (Event $event): int => Cents::of($event->revenue));

        return [
            'name' => $name,
            'events' => $events->count(),
            'average_headcount' => (int) round($headcount / $events->count()),
            'average_revenue_cents' => (int) round($revenue / $events->count()),
            'per_head_cents' => $headcount > 0 ? (int) round($revenue / $headcount) : null,
        ];
    }

    /**
     * Events that have happened and aren't private rentals, with headcount
     * (arrivals; a prepay that never came isn't one).
     *
     * @return Builder<Event>
     */
    private function heldEvents(): Builder
    {
        return Event::query()
            ->whereDoesntHave('privateRental')
            ->whereDate('event_date', '<=', today())
            ->withCount(['attendance as headcount' => fn (Builder $query) => $query->whereNotNull('checked_in_at')]);
    }
}
