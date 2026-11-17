<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Filament\Concerns\ReadsAnalyticsPeriod;
use App\Services\Analytics\DoorFlowAnalytics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * How the Analytics page's arrivals were checked in
 * (DoorFlowAnalytics::channels()).
 */
class CheckInChannelWidget extends StatsOverviewWidget
{
    use ReadsAnalyticsPeriod;

    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected function getHeading(): ?string
    {
        return __('How people were checked in — :period', ['period' => $this->period()->label()]);
    }

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    protected function getStats(): array
    {
        $channels = app(DoorFlowAnalytics::class)->channels($this->period());
        $total = array_sum($channels);

        $labels = [
            DoorFlowAnalytics::CHANNEL_DESK => __('At the desk'),
            DoorFlowAnalytics::CHANNEL_KIOSK => __('Self check-in kiosk'),
            DoorFlowAnalytics::CHANNEL_PREPAID => __('Prepaid, marked arrived'),
            DoorFlowAnalytics::CHANNEL_STAFF => __('Staff checking themselves in'),
            DoorFlowAnalytics::CHANNEL_IMPORTED => __('Imported records'),
        ];

        $descriptions = [
            DoorFlowAnalytics::CHANNEL_PREPAID => __('Booked on an earlier day'),
            DoorFlowAnalytics::CHANNEL_IMPORTED => __('Brought in from earlier records'),
        ];

        if ($total === 0) {
            return [Stat::make(__('Check-ins'), 0)->description(__('Nobody arrived in this period'))];
        }

        return collect($channels)
            ->map(fn (int $count, string $channel) => Stat::make($labels[$channel], (string) Number::format($count))
                ->description(trim(__(':percent% of check-ins', ['percent' => (int) round($count * 100 / $total)])
                    .(isset($descriptions[$channel]) ? ' · '.$descriptions[$channel] : ''))))
            ->values()
            ->all();
    }
}
