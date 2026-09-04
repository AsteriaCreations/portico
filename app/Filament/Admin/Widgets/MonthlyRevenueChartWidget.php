<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Models\AddOnDayPass;
use App\Models\Attendance;
use App\Models\MiscellaneousPayment;
use App\Models\Subscription;
use Filament\Widgets\ChartWidget;

/**
 * The rest of Analytics is windowed to "tonight" or "this week" — every
 * number is hard to interpret without a longer baseline. This is a 12-month
 * rolling view, not a full arbitrary date-range picker (a bigger, separate
 * UI investment), mirroring WeeklyAttendanceChartWidget's own rolling-window
 * iteration shape at a coarser (monthly, not weekly) grain. Three streams,
 * matching RegisterShiftService::revenueBreakdown()'s own categories but
 * aggregated globally per month rather than per-shift: Event (attendance,
 * which already includes Event Add-On revenue folded into amount_paid),
 * Subscription (windowed on paid_on, the transaction date — see
 * SubscriptionOverviewWidget's own comment on why), and Other
 * (MiscellaneousPayment + AddOnDayPass, same as revenueBreakdown()'s "other"
 * bucket, just without the register_shift_id scoping).
 */
class MonthlyRevenueChartWidget extends ChartWidget
{
    private const MONTHS = 12;

    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected ?string $heading = 'Monthly Revenue';

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $months = collect(range(self::MONTHS - 1, 0))
            ->map(fn (int $monthsAgo) => now()->startOfMonth()->subMonths($monthsAgo));

        return [
            'datasets' => [
                [
                    'label' => 'Event',
                    'data' => $months->map(fn ($month) => (float) Attendance::query()
                        ->whereNotNull('checked_in_at')
                        ->whereBetween('checked_in_at', [$month, $month->copy()->endOfMonth()])
                        ->sum('amount_paid'))->all(),
                ],
                [
                    'label' => 'Subscription',
                    'data' => $months->map(fn ($month) => (float) Subscription::query()
                        ->whereBetween('paid_on', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])
                        ->sum('amount_paid'))->all(),
                ],
                [
                    'label' => 'Other',
                    'data' => $months->map(function ($month) {
                        $range = [$month, $month->copy()->endOfMonth()];

                        return (float) MiscellaneousPayment::query()->whereBetween('created_at', $range)->sum('amount')
                            + (float) AddOnDayPass::query()->whereBetween('created_at', $range)->sum('amount_paid');
                    })->all(),
                ],
            ],
            'labels' => $months->map(fn ($month) => $month->format('M Y'))->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['stacked' => true],
                'y' => ['stacked' => true],
            ],
        ];
    }
}
