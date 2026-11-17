<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Filament\Concerns\ReadsAnalyticsPeriod;
use App\Services\Analytics\DoorFlowAnalytics;
use Carbon\CarbonInterval;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * How long people stayed (DoorFlowAnalytics::lengthOfStay()). Only visits
 * with a recorded departure can say, so the share that do is shown with it.
 */
class LengthOfStayWidget extends StatsOverviewWidget
{
    use ReadsAnalyticsPeriod;

    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected function getHeading(): ?string
    {
        return __('How long people stay — :period', ['period' => $this->period()->label()]);
    }

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    protected function getStats(): array
    {
        $stay = app(DoorFlowAnalytics::class)->lengthOfStay($this->period());
        $basis = __('From :sample of :arrivals visits with a departure recorded', ['sample' => $stay['sample'], 'arrivals' => $stay['arrivals']]);

        if ($stay['sample'] === 0) {
            return [Stat::make(__('Typical stay'), '—')->description($basis)];
        }

        $share = fn (int $part): string => __(':percent% of those visits', ['percent' => (int) round($part * 100 / $stay['sample'])]);

        return [
            Stat::make(__('Typical stay'), $this->duration($stay['median_minutes']))
                ->description($basis),
            Stat::make(__('Average stay'), $this->duration($stay['average_minutes'])),
            Stat::make(__('Under an hour'), $stay['under_hour'])->description($share($stay['under_hour'])),
            Stat::make(__('One to three hours'), $stay['one_to_three'])->description($share($stay['one_to_three'])),
            Stat::make(__('Three hours or more'), $stay['over_three'])->description($share($stay['over_three'])),
        ];
    }

    /**
     * "2h 15m", in the installation's language.
     */
    private function duration(int $minutes): string
    {
        return CarbonInterval::minutes($minutes)->cascade()->forHumans(['short' => true, 'parts' => 2]);
    }
}
