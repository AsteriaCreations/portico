<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Filament\Admin\Resources\PaymentCorrections\PaymentCorrectionResource;
use App\Filament\Admin\Resources\VisitRemovals\VisitRemovalResource;
use App\Filament\Concerns\ReadsAnalyticsPeriod;
use App\Models\MembershipSetting;
use App\Services\Analytics\StaffAnalytics;
use App\Support\Cents;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The desk's after-the-fact changes in the Analytics page's period
 * (StaffAnalytics::deskExceptions()). A refund or cash-exchange tile shows
 * only once the club uses that feature, or has records of it.
 */
class DeskExceptionsWidget extends StatsOverviewWidget
{
    use ReadsAnalyticsPeriod;

    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected function getHeading(): ?string
    {
        return __('Corrections at the desk — :period', ['period' => $this->period()->label()]);
    }

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    protected function getStats(): array
    {
        $desk = app(StaffAnalytics::class)->deskExceptions($this->period());
        $settings = MembershipSetting::current();
        $money = fn (int $cents): string => MembershipSetting::formatMoney(Cents::toFloat($cents));

        $stats = [
            Stat::make(__('Visits removed'), $desk['removals'])
                ->description($desk['removals_after_close'] > 0
                    ? __(':amount refunded · :count after the shift closed', ['amount' => $money($desk['removals_cents']), 'count' => $desk['removals_after_close']])
                    : __(':amount refunded', ['amount' => $money($desk['removals_cents'])]))
                ->url(VisitRemovalResource::getUrl('index')),
            Stat::make(__('Entries turned into subscriptions'), $desk['corrections'])
                ->description(__(':amount of subscriptions', ['amount' => $money($desk['corrections_cents'])]))
                ->url(PaymentCorrectionResource::getUrl('index')),
        ];

        if ($settings->desk_refunds_enabled || $desk['refunds'] > 0) {
            $stats[] = Stat::make(__('Refunds at the desk'), $desk['refunds'])
                ->description(__(':amount refunded', ['amount' => $money($desk['refunds_cents'])]));
        }

        if ($settings->cash_exchanges_enabled || $desk['exchanges'] > 0) {
            $stats[] = Stat::make(__('Cash given for electronic payments'), $desk['exchanges'])
                ->description(__(':amount in cash', ['amount' => $money($desk['exchanges_cents'])]));
        }

        return $stats;
    }
}
