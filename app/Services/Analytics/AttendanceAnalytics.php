<?php

namespace App\Services\Analytics;

use App\Enums\EntryCoverageSource;
use App\Models\Attendance;
use App\Models\Member;
use App\Support\AnalyticsPeriod;
use App\Support\Cents;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Visit counts and what those visits brought in, for the Analytics page. A
 * visit is an attendance row that actually arrived (checked_in_at set): a
 * prepay still on its way is not one.
 */
class AttendanceAnalytics
{
    /**
     * @return array{check_ins: int, door_take_cents: int}
     */
    public function tonight(): array
    {
        $query = Attendance::query()
            ->whereHas('event', fn ($query) => $query->whereDate('event_date', today()))
            ->whereNotNull('checked_in_at');

        return [
            'check_ins' => (clone $query)->count(),
            'door_take_cents' => Cents::of((clone $query)->sum('amount_paid')),
        ];
    }

    public function arrivals(AnalyticsPeriod $period): int
    {
        return $period->constrain(Attendance::query(), 'checked_in_at')->count();
    }

    /**
     * Different people who arrived at least once in the period.
     */
    public function uniqueVisitors(AnalyticsPeriod $period): int
    {
        return $period->constrain(Attendance::query(), 'checked_in_at')->distinct('member_id')->count('member_id');
    }

    /**
     * People whose very first visit fell in the period
     * (Member::scopeFirstVisitedBetween()).
     */
    public function firstTimeVisitors(AnalyticsPeriod $period): int
    {
        return Member::query()->firstVisitedBetween($period->from, $period->untilExclusive)->count();
    }

    /**
     * Windowed on the event's own date, not the arrival time, so an
     * overnight event's after-midnight arrivals stay with their event.
     *
     * @return Collection<int, array{name: string, visits: int, revenue_cents: int}>
     */
    public function byEventType(AnalyticsPeriod $period): Collection
    {
        $query = DB::table('attendance')
            ->join('events', 'events.id', '=', 'attendance.event_id')
            ->join('event_types', 'event_types.id', '=', 'events.event_type_id')
            ->whereNotNull('attendance.checked_in_at')
            ->groupBy('event_types.id', 'event_types.name', 'event_types.sort_order')
            ->orderBy('event_types.sort_order')
            ->selectRaw('event_types.name as name, COUNT(*) as visits, SUM(attendance.amount_paid) as revenue');

        return $period->constrainDate($query, 'events.event_date')->get()->map($this->visitRow(...));
    }

    /**
     * @return Collection<int, array{name: string, visits: int, revenue_cents: int}>
     */
    public function byCategory(AnalyticsPeriod $period): Collection
    {
        $query = DB::table('attendance')
            ->join('members', 'members.id', '=', 'attendance.member_id')
            ->join('categories', 'categories.id', '=', 'members.category_id')
            ->groupBy('categories.id', 'categories.name', 'categories.sort_order')
            ->orderBy('categories.sort_order')
            ->selectRaw('categories.name as name, COUNT(*) as visits, SUM(attendance.amount_paid) as revenue');

        return $period->constrain($query, 'attendance.checked_in_at')->get()->map($this->visitRow(...));
    }

    /**
     * Entry fees waived by a per-event comp, by reason: revenue foregone, not
     * cash owed. A visit a subscription or comped category already covered
     * isn't one.
     *
     * @return Collection<int, array{name: string, comps: int, foregone_cents: int}>
     */
    public function compCostByReason(AnalyticsPeriod $period): Collection
    {
        $query = DB::table('attendance')
            ->join('comp_reasons', 'comp_reasons.id', '=', 'attendance.comp_reason_id')
            ->where('attendance.entry_covered_by', EntryCoverageSource::EventComp->value)
            ->groupBy('comp_reasons.id', 'comp_reasons.name', 'comp_reasons.sort_order')
            ->orderBy('comp_reasons.sort_order')
            ->selectRaw('comp_reasons.name as name, COUNT(*) as comps, SUM(attendance.entry_coverage) as foregone');

        return $period->constrain($query, 'attendance.checked_in_at')->get()->map(fn (object $row): array => [
            'name' => $row->name,
            'comps' => (int) $row->comps,
            'foregone_cents' => Cents::of($row->foregone),
        ]);
    }

    /**
     * Add-ons sold, by the name snapshotted on the sale, windowed on when it
     * was sold rather than when its visit arrived.
     *
     * @return Collection<int, array{name: string, sold: int, revenue_cents: int}>
     */
    public function addOnSales(AnalyticsPeriod $period): Collection
    {
        $query = DB::table('attendance_add_ons')
            ->join('attendance', 'attendance.id', '=', 'attendance_add_ons.attendance_id')
            ->whereNotNull('attendance.checked_in_at')
            ->groupBy('attendance_add_ons.name')
            ->orderBy('attendance_add_ons.name')
            ->selectRaw('attendance_add_ons.name as name, COUNT(*) as sold, SUM(attendance_add_ons.price) as revenue');

        return $period->constrain($query, 'attendance_add_ons.created_at')->get()->map(fn (object $row): array => [
            'name' => $row->name,
            'sold' => (int) $row->sold,
            'revenue_cents' => Cents::of($row->revenue),
        ]);
    }

    /**
     * @return array{name: string, visits: int, revenue_cents: int}
     */
    private function visitRow(object $row): array
    {
        return [
            'name' => $row->name,
            'visits' => (int) $row->visits,
            'revenue_cents' => Cents::of($row->revenue),
        ];
    }
}
