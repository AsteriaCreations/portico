<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class WeeklyCategoryBreakdownWidget extends StatsOverviewWidget
{
    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected ?string $heading = 'This Week — By Category';

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    protected function getStats(): array
    {
        $rows = DB::table('attendance')
            ->join('members', 'members.id', '=', 'attendance.member_id')
            ->join('categories', 'categories.id', '=', 'members.category_id')
            ->whereNotNull('attendance.checked_in_at')
            ->whereBetween('attendance.checked_in_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->groupBy('categories.id', 'categories.name', 'categories.sort_order')
            ->orderBy('categories.sort_order')
            ->selectRaw('categories.name as name, COUNT(*) as visits, SUM(attendance.amount_paid) as revenue')
            ->get();

        return $rows
            ->map(fn ($row) => Stat::make($row->name, "{$row->visits} visits")
                ->description('$'.number_format($row->revenue, 2).' collected'))
            ->all();
    }
}
