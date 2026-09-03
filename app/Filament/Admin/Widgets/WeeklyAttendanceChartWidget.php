<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Models\Attendance;
use Filament\Widgets\ChartWidget;

class WeeklyAttendanceChartWidget extends ChartWidget
{
    private const WEEKS = 8;

    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected ?string $heading = 'Weekly Attendance';

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $weeks = collect(range(self::WEEKS - 1, 0))
            ->map(fn (int $weeksAgo) => now()->subWeeks($weeksAgo)->startOfWeek());

        return [
            'datasets' => [[
                'label' => 'Attendance',
                'data' => $weeks->map(fn ($weekStart) => Attendance::query()
                    ->whereBetween('checked_in_at', [$weekStart, $weekStart->copy()->endOfWeek()])
                    ->count())->all(),
            ]],
            'labels' => $weeks->map(fn ($weekStart) => $weekStart->format('M j'))->all(),
        ];
    }

    /**
     * Attendance is a headcount — a whole number of members — so the y-axis
     * shouldn't offer fractional ticks (Chart.js's default "nice number"
     * step picking can land on e.g. 0.5 for a low-count week).
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
