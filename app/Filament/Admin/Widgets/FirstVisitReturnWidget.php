<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Filament\Concerns\ReadsAnalyticsPeriod;
use App\Services\Analytics\RetentionAnalytics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Of the people whose first visit fell in the Analytics page's period, how
 * many came back within 30, 60 and 90 days (RetentionAnalytics::returnRates()).
 */
class FirstVisitReturnWidget extends StatsOverviewWidget
{
    use ReadsAnalyticsPeriod;

    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected function getHeading(): ?string
    {
        return __('Do first-timers come back? — :period', ['period' => $this->period()->label()]);
    }

    protected function getDescription(): ?string
    {
        return __('People whose first visit fell in the period. Coming back means a visit on a later day; someone is only counted once their 30, 60 or 90 days are up.');
    }

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    protected function getStats(): array
    {
        $rates = app(RetentionAnalytics::class)->returnRates($this->period());

        $stats = [Stat::make(__('First-time visitors'), $rates['first_timers'])];

        foreach ($rates['windows'] as $days => $window) {
            $stats[] = $window['eligible'] === 0
                ? Stat::make(__('Back within :days days', ['days' => $days]), '—')
                    ->description(__('Too early to say'))
                : Stat::make(
                    __('Back within :days days', ['days' => $days]),
                    __(':percent%', ['percent' => (int) round($window['returned'] * 100 / $window['eligible'])]),
                )->description(__(':returned of :eligible whose :days days are up', [
                    'returned' => $window['returned'],
                    'eligible' => $window['eligible'],
                    'days' => $days,
                ]));
        }

        return $stats;
    }
}
