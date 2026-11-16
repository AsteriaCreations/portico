<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Filament\Concerns\ReadsAnalyticsPeriod;
use App\Models\MembershipSetting;
use App\Services\Analytics\AttendanceAnalytics;
use App\Support\Cents;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Visits and what they paid, per member category, for arrivals in the
 * Analytics page's period.
 */
class CategoryBreakdownWidget extends StatsOverviewWidget
{
    use ReadsAnalyticsPeriod;

    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected function getHeading(): ?string
    {
        return __('Visits by category — :period', ['period' => $this->period()->label()]);
    }

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    protected function getStats(): array
    {
        return app(AttendanceAnalytics::class)->byCategory($this->period())
            ->map(fn (array $row) => Stat::make($row['name'], trans_choice(':count visit|:count visits', $row['visits']))
                ->description(__(':amount collected', ['amount' => MembershipSetting::formatMoney(Cents::toFloat($row['revenue_cents']))])))
            ->all();
    }
}
