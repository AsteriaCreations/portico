<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Filament\Concerns\ReadsAnalyticsPeriod;
use App\Models\AddOn;
use App\Models\MembershipSetting;
use App\Services\Analytics\MembershipAnalytics;
use App\Services\Analytics\RevenueAnalytics;
use App\Support\Cents;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Subscriptions are a core recurring revenue stream. The subscriber counts
 * are for the current month whatever period the Analytics page shows (who
 * holds one now); the revenue figure follows the page's period, windowed on
 * paid_on (when money actually changed hands), not covered_month, matching
 * how cash reporting elsewhere windows on transaction date rather than
 * covered period.
 */
class SubscriptionOverviewWidget extends StatsOverviewWidget
{
    use ReadsAnalyticsPeriod;

    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected function getHeading(): ?string
    {
        return __('Subscriptions');
    }

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    protected function getStats(): array
    {
        $membership = app(MembershipAnalytics::class);

        $stats = [
            Stat::make(__('Active Regular subscribers'), $membership->activeSubscribers(AddOn::entry(), now()))
                ->description(__('This month')),
        ];

        // Pool subscriptions aren't retroactively hidden once purchased (see
        // AddOn::priceFor()'s own comment) -- but a club that's turned pool
        // off entirely doesn't need this count cluttering the page.
        $poolAddOn = AddOn::pool();
        if ($poolAddOn && MembershipSetting::current()->pool_enabled) {
            $stats[] = Stat::make(__('Active Pool subscribers'), $membership->activeSubscribers($poolAddOn, now()))
                ->description(__('This month'));
        }

        $stats[] = Stat::make(
            __('Subscription revenue'),
            MembershipSetting::formatMoney(Cents::toFloat(app(RevenueAnalytics::class)->subscriptionCents($this->period()))),
        )->description($this->period()->label());

        return $stats;
    }
}
