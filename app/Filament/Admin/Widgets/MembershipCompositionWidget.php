<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * All-time active-member composition by category -- the roster itself, not
 * this-week visits (WeeklyCategoryBreakdownWidget's own concern). Least
 * time-sensitive of the seven ranked reporting gaps, but rounds out the
 * series: the club's whole makeup had no view anywhere on Analytics.
 */
class MembershipCompositionWidget extends StatsOverviewWidget
{
    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected ?string $heading = 'Active Membership — By Category';

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    protected function getStats(): array
    {
        $rows = DB::table('members')
            ->join('categories', 'categories.id', '=', 'members.category_id')
            ->where('members.is_active', true)
            ->groupBy('categories.id', 'categories.name', 'categories.sort_order')
            ->orderBy('categories.sort_order')
            ->selectRaw('categories.name as name, COUNT(*) as members')
            ->get();

        return $rows
            ->map(fn ($row) => Stat::make($row->name, "{$row->members} members"))
            ->all();
    }
}
