<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Filament\Concerns\ReadsAnalyticsPeriod;
use App\Models\MembershipSetting;
use App\Models\PaymentMethod;
use App\Services\Analytics\RevenueAnalytics;
use App\Support\Cents;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * How the Analytics page's period was paid: every payment the revenue trend
 * counts, split by method (RevenueAnalytics::byPaymentMethod()), with each
 * method's share of the total.
 */
class PaymentMethodMixWidget extends StatsOverviewWidget
{
    use ReadsAnalyticsPeriod;

    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected function getHeading(): ?string
    {
        return __('How it was paid — :period', ['period' => $this->period()->label()]);
    }

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    protected function getStats(): array
    {
        $methods = app(RevenueAnalytics::class)->byPaymentMethod($this->period());
        $total = $methods->sum('cents');
        // A method keeps the label it has today; one since deleted shows its code.
        $labels = PaymentMethod::query()->pluck('label', 'code');

        return $methods
            ->map(fn (array $method) => Stat::make(
                $method['code'] === null ? __('No method recorded') : ($labels[$method['code']] ?? $method['code']),
                MembershipSetting::formatMoney(Cents::toFloat($method['cents'])),
            )->description(trans_choice(
                ':percent% of the total · :count payment|:percent% of the total · :count payments',
                $method['payments'],
                ['percent' => $total > 0 ? (int) round($method['cents'] * 100 / $total) : 0],
            )))
            ->all();
    }
}
