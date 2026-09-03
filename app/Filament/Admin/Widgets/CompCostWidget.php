<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\EntryCoverageSource;
use App\Enums\Role;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Foregone revenue from per-event comps (App\Enums\EntryCoverageSource::
 * EventComp), grouped by comp_reasons, this week -- useful for spotting
 * whether a comp reason is being overused, distinct from Voucher
 * LiabilityWidget's real cash-at-risk figure. Scoped to EventComp
 * specifically, not Host (which isn't reason-tagged the same way and is
 * already its own documented, separately-tested concept). Not gated by
 * showrunner_comp_requests_enabled -- that flag only covers the Showrunner
 * request *pipeline*, not per-event comp itself.
 */
class CompCostWidget extends StatsOverviewWidget
{
    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected ?string $heading = 'Comp Cost This Week';

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    protected function getStats(): array
    {
        $rows = DB::table('attendance')
            ->join('comp_reasons', 'comp_reasons.id', '=', 'attendance.comp_reason_id')
            ->whereNotNull('attendance.checked_in_at')
            ->where('attendance.entry_covered_by', EntryCoverageSource::EventComp->value)
            ->whereBetween('attendance.checked_in_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->groupBy('comp_reasons.id', 'comp_reasons.name', 'comp_reasons.sort_order')
            ->orderBy('comp_reasons.sort_order')
            ->selectRaw('comp_reasons.name as name, COUNT(*) as comps, SUM(attendance.entry_coverage) as foregone')
            ->get();

        return $rows
            ->map(fn ($row) => Stat::make($row->name, "{$row->comps} comps")
                ->description('$'.number_format($row->foregone, 2).' foregone'))
            ->all();
    }
}
