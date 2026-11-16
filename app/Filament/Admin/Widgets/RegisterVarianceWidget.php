<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Filament\Admin\Resources\RegisterShifts\RegisterShiftResource;
use App\Filament\Concerns\ReadsAnalyticsPeriod;
use App\Models\MembershipSetting;
use App\Services\Analytics\RevenueAnalytics;
use App\Support\Cents;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Variance is already visible per-shift as a color-coded column on
 * RegisterShiftsTable -- the gap this closes is that nobody sees it
 * aggregated without manually paging through shift history. Summed over the
 * shifts closed in the Analytics page's period
 * (RevenueAnalytics::registerVariance()).
 */
class RegisterVarianceWidget extends StatsOverviewWidget
{
    use ReadsAnalyticsPeriod;

    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected function getHeading(): ?string
    {
        return __('Register shifts closed — :period', ['period' => $this->period()->label()]);
    }

    // Also hidden when the club has register-shift tracking turned off
    // entirely — no point surfacing a variance figure for a feature that's
    // not in use.
    public static function canView(): bool
    {
        return (auth()->user()?->role->atLeast(Role::Manager) ?? false)
            && MembershipSetting::current()->register_shifts_enabled;
    }

    protected function getStats(): array
    {
        $variance = app(RevenueAnalytics::class)->registerVariance($this->period());
        // In cents: a float total of balanced shifts isn't reliably 0.0.
        $total = $variance['total_cents'];

        return [
            Stat::make(__('Total register variance'), MembershipSetting::formatMoney(Cents::toFloat($total)))
                ->color(match (true) {
                    $total === 0 => 'success',
                    $total > 0 => 'warning',
                    default => 'danger',
                })
                // Said in words too, not by colour alone.
                ->description(match (true) {
                    $total === 0 => __('Balanced'),
                    $total > 0 => __('Over'),
                    default => __('Short'),
                })
                ->descriptionIcon(match (true) {
                    $total === 0 => Heroicon::CheckCircle,
                    $total > 0 => Heroicon::ArrowUpCircle,
                    default => Heroicon::ArrowDownCircle,
                }),
            Stat::make(__('Shifts with variance'), __(':count of :total', ['count' => $variance['with_variance'], 'total' => $variance['shifts']]))
                ->url(RegisterShiftResource::getUrl('index')),
        ];
    }
}
