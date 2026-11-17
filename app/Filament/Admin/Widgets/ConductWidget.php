<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Filament\Concerns\ReadsAnalyticsPeriod;
use App\Models\MembershipSetting;
use App\Services\Analytics\StaffAnalytics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Conduct records made in the Analytics page's period
 * (StaffAnalytics::conduct()). Counts only: who and why stay on the member's
 * own record.
 */
class ConductWidget extends StatsOverviewWidget
{
    use ReadsAnalyticsPeriod;

    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected function getHeading(): ?string
    {
        return __('Conduct — :period', ['period' => $this->period()->label()]);
    }

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    protected function getStats(): array
    {
        $conduct = app(StaffAnalytics::class)->conduct($this->period());
        $stats = [];

        if (MembershipSetting::current()->behavior_notes_enabled || $conduct['notes'] > 0) {
            $stats[] = Stat::make(__('Behavior notes'), $conduct['notes'] - $conduct['retracted'])
                ->description(__('Not counting :count retracted', ['count' => $conduct['retracted']]));
        }

        return [
            ...$stats,
            Stat::make(__('Banned'), $conduct['banned'])
                ->description(trans_choice(':count ban lifted|:count bans lifted', $conduct['unbanned'])),
            Stat::make(__('Put on the watchlist'), $conduct['watchlisted'])
                ->description(__(':count taken off', ['count' => $conduct['unwatchlisted']])),
        ];
    }
}
