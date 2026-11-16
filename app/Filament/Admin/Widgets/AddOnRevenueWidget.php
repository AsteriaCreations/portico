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
 * Event Add-Ons (Private room rental, Sleepover, ...) deliberately fold
 * their price into attendance.amount_paid so RegisterShiftService needs
 * zero changes -- see the commit history ("Event Add-Ons"). That
 * design choice is exactly why this revenue is otherwise invisible; this
 * widget is the one place it's broken back out, over the Analytics page's
 * period. Grouped by the snapshotted attendance_add_ons.name column (not
 * add_on_id), same "never let a later catalog edit rewrite history"
 * principle already established for add-ons.
 */
class AddOnRevenueWidget extends StatsOverviewWidget
{
    use ReadsAnalyticsPeriod;

    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected function getHeading(): ?string
    {
        return __('Add-on revenue — :period', ['period' => $this->period()->label()]);
    }

    // Also hidden when the club has Event Add-Ons turned off entirely — no
    // point surfacing a revenue breakdown for a feature that's not in use.
    public static function canView(): bool
    {
        return (auth()->user()?->role->atLeast(Role::Manager) ?? false)
            && MembershipSetting::current()->add_ons_enabled;
    }

    protected function getStats(): array
    {
        return app(AttendanceAnalytics::class)->addOnSales($this->period())
            ->map(fn (array $row) => Stat::make($row['name'], __(':count sold', ['count' => $row['sold']]))
                ->description(__(':amount collected', ['amount' => MembershipSetting::formatMoney(Cents::toFloat($row['revenue_cents']))])))
            ->all();
    }
}
