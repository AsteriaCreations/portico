<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Services\Analytics\RetentionAnalytics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * Where the active membership stands today on the way from a record to a
 * subscription (RetentionAnalytics::funnel()). As it stands now, so it
 * doesn't follow the Analytics page's period.
 */
class MemberFunnelWidget extends StatsOverviewWidget
{
    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected function getHeading(): ?string
    {
        return __('From record to subscriber — today');
    }

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    protected function getStats(): array
    {
        $funnel = app(RetentionAnalytics::class)->funnel();
        $share = fn (int $part): string => $funnel['members'] === 0
            ? __('No active members yet')
            : __(':percent% of active members', ['percent' => (int) round($part * 100 / $funnel['members'])]);
        $count = fn (int $value): string => (string) Number::format($value);

        return [
            Stat::make(__('Active members'), $count($funnel['members'])),
            Stat::make(__('Have visited'), $count($funnel['visited']))->description($share($funnel['visited'])),
            Stat::make(__('Can subscribe'), $count($funnel['eligible']))->description($share($funnel['eligible'])),
            Stat::make(__('Subscribed this month'), $count($funnel['subscribed']))->description($share($funnel['subscribed'])),
        ];
    }
}
