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
 * Foregone revenue from per-event comps (App\Enums\EntryCoverageSource::
 * EventComp), grouped by comp_reasons, over the Analytics page's period --
 * useful for spotting whether a comp reason is being overused, distinct from
 * VoucherLiabilityWidget's real cash-at-risk figure. Scoped to EventComp
 * specifically, not Host (which isn't reason-tagged the same way and is
 * already its own documented, separately-tested concept). Not gated by
 * showrunner_comp_requests_enabled -- that flag only covers the Showrunner
 * request *pipeline*, not per-event comp itself.
 */
class CompCostWidget extends StatsOverviewWidget
{
    use ReadsAnalyticsPeriod;

    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected function getHeading(): ?string
    {
        return __('Comp cost — :period', ['period' => $this->period()->label()]);
    }

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    protected function getStats(): array
    {
        return app(AttendanceAnalytics::class)->compCostByReason($this->period())
            ->map(fn (array $row) => Stat::make($row['name'], trans_choice(':count comp|:count comps', $row['comps']))
                ->description(__(':amount foregone', ['amount' => MembershipSetting::formatMoney(Cents::toFloat($row['foregone_cents']))])))
            ->all();
    }
}
