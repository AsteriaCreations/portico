<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Models\Member;
use Filament\Widgets\ChartWidget;

/**
 * 12-month rolling count of newly-created members, same iteration shape as
 * MonthlyRevenueChartWidget. Known, deliberately accepted caveat: created_at
 * is the right signal for anyone created through the live app (guest
 * registration, admin create, Prospective promotion), but a one-time bulk
 * import of a historical roster won't backdate created_at, so those members
 * cluster on the day the import ran. date_vetted isn't a substitute either
 * -- it's a plain optional field on MemberForm, never auto-set, so it's
 * sparse for live-created members too. Not engineered around, matching this
 * codebase's existing "note it, don't special-case a one-time historical
 * event" philosophy.
 */
class NewMembersChartWidget extends ChartWidget
{
    private const MONTHS = 12;

    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected ?string $heading = 'New Members';

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
            'datasets' => [[
                'label' => 'New members',
                'data' => $months->map(fn ($month) => Member::query()
                    ->whereBetween('created_at', [$month, $month->copy()->endOfMonth()])
                    ->count())->all(),
            ]],
            'labels' => $months->map(fn ($month) => $month->format('M Y'))->all(),
        ];
    }

    /**
     * A member count is a whole number, same reasoning as
     * WeeklyAttendanceChartWidget's own y-axis override.
     */
    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => [
                    'ticks' => [
                        'stepSize' => 1,
                        'precision' => 0,
                    ],
                ],
            ],
        ];
    }
}
