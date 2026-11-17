<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Filament\Concerns\ReadsAnalyticsPeriod;
use App\Models\MembershipSetting;
use App\Services\Analytics\SubscriptionAnalytics;
use App\Support\Cents;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * What subscribers got out of the Regular subscription in the month the
 * Analytics page's period ends in (SubscriptionAnalytics::usage()).
 */
class SubscriptionUsageWidget extends StatsOverviewWidget
{
    use ReadsAnalyticsPeriod;

    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected function getHeading(): ?string
    {
        return __('Use of the subscription — :month', ['month' => $this->period()->closingMonth()->translatedFormat('M Y')]);
    }

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    protected function getStats(): array
    {
        $usage = app(SubscriptionAnalytics::class)->usage($this->period()->closingMonth());
        $money = fn (int $cents): string => MembershipSetting::formatMoney(Cents::toFloat($cents));

        if ($usage['subscribers'] === 0) {
            return [Stat::make(__('Subscribers'), 0)->description(__('Nobody subscribed that month'))];
        }

        return [
            Stat::make(__('Visits on the subscription'), (string) Number::format($usage['visits']))
                ->description(__(':average a subscriber', ['average' => Number::format($usage['visits'] / $usage['subscribers'], 1)])),
            Stat::make(__('Subscribers who didn\'t visit'), (string) Number::format($usage['no_visit']))
                ->description(__(':percent% of subscribers', ['percent' => (int) round($usage['no_visit'] * 100 / $usage['subscribers'])])),
            Stat::make(__('Entry fees covered, per subscriber'), $money($usage['average_coverage_cents']))
                ->description($usage['price_cents'] === null
                    ? __(':total covered in all', ['total' => $money($usage['coverage_cents'])])
                    : __('A month costs :price · :total covered in all', [
                        'price' => $money($usage['price_cents']),
                        'total' => $money($usage['coverage_cents']),
                    ])),
        ];
    }
}
