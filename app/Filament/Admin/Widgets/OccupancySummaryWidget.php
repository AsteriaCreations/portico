<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Filament\Concerns\ReadsAnalyticsPeriod;
use App\Services\Analytics\DoorFlowAnalytics;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * How full the building got on the Analytics page's nights
 * (DoorFlowAnalytics::occupancySummary()).
 */
class OccupancySummaryWidget extends StatsOverviewWidget
{
    use ReadsAnalyticsPeriod;

    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected function getHeading(): ?string
    {
        return __('How full it got — :period', ['period' => $this->period()->label()]);
    }

    protected function getDescription(): ?string
    {
        return __('The most people inside at once each night, from check-ins and departures recorded in the app. Someone never marked as leaving counts as staying.');
    }

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    protected function getStats(): array
    {
        $summary = app(DoorFlowAnalytics::class)->occupancySummary($this->period());

        if ($summary['nights'] === 0) {
            return [Stat::make(__('Nights'), 0)->description(__('No check-ins recorded in the app in this period'))];
        }

        $busiest = $summary['busiest'];

        $stats = [
            Stat::make(__('Nights'), $summary['nights']),
            Stat::make(__('Busiest night'), $busiest['peak'])
                ->description($busiest['peak_at'] === null
                    ? CarbonImmutable::parse($busiest['date'])->isoFormat('ll')
                    : __(':date, at :time', [
                        'date' => CarbonImmutable::parse($busiest['date'])->isoFormat('ll'),
                        'time' => $busiest['peak_at']->isoFormat('LT'),
                    ])),
            Stat::make(__('Average peak'), $summary['average_peak']),
        ];

        if ($summary['capacity'] !== null) {
            $stats[] = Stat::make(__('Nights at capacity'), $summary['at_capacity'])
                ->description(__('Capacity :capacity (today\'s setting)', ['capacity' => $summary['capacity']]));
            $stats[] = Stat::make(__('Nights at 90% or more'), $summary['near_capacity']);
        }

        return $stats;
    }
}
