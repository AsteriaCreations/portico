<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Filament\Concerns\ReadsAnalyticsPeriod;
use App\Models\MembershipSetting;
use App\Services\Analytics\RetentionAnalytics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * What became of the guests who first came in the Analytics page's period
 * (RetentionAnalytics::guestConversion()).
 */
class GuestConversionWidget extends StatsOverviewWidget
{
    use ReadsAnalyticsPeriod;

    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected function getHeading(): ?string
    {
        return __('Guests — :period', ['period' => $this->period()->label()]);
    }

    // Also hidden when the club doesn't take guests at all.
    public static function canView(): bool
    {
        return (auth()->user()?->role->atLeast(Role::Manager) ?? false)
            && MembershipSetting::current()->guests_enabled;
    }

    protected function getStats(): array
    {
        $guests = app(RetentionAnalytics::class)->guestConversion($this->period());
        $share = fn (int $part): string => $guests['guests'] === 0
            ? __('No guests in the period')
            : __(':percent% of those guests', ['percent' => (int) round($part * 100 / $guests['guests'])]);

        return [
            Stat::make(__('Guests on a first visit'), $guests['guests'])
                ->description(__('Brought in by a member')),
            Stat::make(__('Came back'), $guests['returned'])
                ->description($share($guests['returned'])),
            Stat::make(__('No longer a Guest'), $guests['moved_on'])
                ->description($share($guests['moved_on'])),
        ];
    }
}
