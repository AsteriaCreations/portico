<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Models\MembershipSetting;
use App\Models\RegisterShift;
use App\Services\RegisterShiftService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Variance is already visible per-shift as a color-coded column on
 * RegisterShiftsTable -- the gap this closes is that nobody sees it
 * aggregated without manually paging through shift history. RegisterShift
 * Service::variance() isn't a pure SQL aggregate (it composes cashReceived()
 * /totalDrops() across four tables per shift), so this loops over shifts
 * closed this week -- a small, bounded set, nights operated per week -- and
 * sums in PHP rather than one raw query.
 */
class RegisterVarianceWidget extends StatsOverviewWidget
{
    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    // Also hidden when the club has register-shift tracking turned off
    // entirely — no point surfacing a variance figure for a feature that's
    // not in use.
    public static function canView(): bool
    {
        return (auth()->user()?->role->atLeast(Role::Manager) ?? false)
            && MembershipSetting::current()->register_shifts_enabled;
    }

    protected function getStats(): array
    {
        $shifts = RegisterShift::query()
            ->whereNotNull('closed_at')
            ->whereBetween('closed_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->get();

        $service = app(RegisterShiftService::class);
        $variances = $shifts->map(fn (RegisterShift $shift) => $service->variance($shift));
        $total = $variances->sum();

        return [
            Stat::make('Total register variance this week', '$'.number_format($total, 2))
                ->color(match (true) {
                    $total == 0.0 => 'success',
                    $total > 0 => 'warning',
                    default => 'danger',
                }),
            Stat::make('Shifts with variance', $variances->filter(fn (?float $v) => $v !== null && $v !== 0.0)->count().' of '.$shifts->count()),
        ];
    }
}
