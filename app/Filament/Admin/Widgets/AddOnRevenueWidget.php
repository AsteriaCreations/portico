<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Models\MembershipSetting;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Event Add-Ons (Private room rental, Sleepover, ...) deliberately fold
 * their price into attendance.amount_paid so RegisterShiftService needs
 * zero changes -- see the commit history ("Event Add-Ons"). That
 * design choice is exactly why this revenue is otherwise invisible; this
 * widget is the one place it's broken back out. Grouped by the snapshotted
 * attendance_add_ons.name column (not add_on_id), same "never let a later
 * catalog edit rewrite history" principle already established for add-ons.
 */
class AddOnRevenueWidget extends StatsOverviewWidget
{
    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected ?string $heading = 'Add-On Revenue This Week';

    // Also hidden when the club has Event Add-Ons turned off entirely — no
    // point surfacing a revenue breakdown for a feature that's not in use.
    public static function canView(): bool
    {
        return (auth()->user()?->role->atLeast(Role::Manager) ?? false)
            && MembershipSetting::current()->add_ons_enabled;
    }

    protected function getStats(): array
    {
        $rows = DB::table('attendance_add_ons')
            ->join('attendance', 'attendance.id', '=', 'attendance_add_ons.attendance_id')
            ->whereNotNull('attendance.checked_in_at')
            ->whereBetween('attendance_add_ons.created_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->groupBy('attendance_add_ons.name')
            ->orderBy('attendance_add_ons.name')
            ->selectRaw('attendance_add_ons.name as name, COUNT(*) as qty, SUM(attendance_add_ons.price) as revenue')
            ->get();

        return $rows
            ->map(fn ($row) => Stat::make($row->name, "{$row->qty} sold")
                ->description('$'.number_format($row->revenue, 2).' collected'))
            ->all();
    }
}
