<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Models\MembershipSetting;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class WeeklySummaryWidget extends StatsOverviewWidget
{
    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected function getHeading(): ?string
    {
        return __('This Week');
    }

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    protected function getStats(): array
    {
        $rows = DB::table('attendance')
            ->join('events', 'events.id', '=', 'attendance.event_id')
            ->join('event_types', 'event_types.id', '=', 'events.event_type_id')
            ->whereNotNull('attendance.checked_in_at')
            ->whereBetween('events.event_date', [now()->startOfWeek(), now()->endOfWeek()])
            ->groupBy('event_types.id', 'event_types.name', 'event_types.sort_order')
            ->orderBy('event_types.sort_order')
            ->selectRaw('event_types.name as name, COUNT(*) as visits, SUM(attendance.amount_paid) as revenue')
            ->get();

        return $rows
            ->map(fn ($row) => Stat::make($row->name, trans_choice(':count visit|:count visits', $row->visits))
                ->description(__(':amount collected', ['amount' => MembershipSetting::formatMoney($row->revenue)])))
            ->all();
    }
}
