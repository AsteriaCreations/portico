<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Filament\Admin\Resources\Members\MemberResource;
use App\Filament\Admin\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Concerns\ReadsAnalyticsPeriod;
use App\Models\AddOn;
use App\Models\MembershipSetting;
use App\Services\Analytics\AttendanceAnalytics;
use App\Services\Analytics\MembershipAnalytics;
use App\Services\Analytics\RevenueAnalytics;
use App\Support\Cents;
use Closure;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * The Analytics page's period in five numbers, each set against the
 * like-for-like stretch before it (AnalyticsPeriod::previous()). A period
 * still under way is compared as far as it has run -- the first eighteen
 * days of this month against the first eighteen of last -- so a half-finished
 * week never reads as a collapse. Subscribers are a monthly thing, so that
 * one is the period's last month against the month before.
 */
class PeriodHeadlineWidget extends StatsOverviewWidget
{
    use ReadsAnalyticsPeriod;

    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected function getHeading(): ?string
    {
        $period = $this->period();

        return $period->elapsed() === $period
            ? $period->label()
            : __(':period, so far', ['period' => $period->label()]);
    }

    protected function getDescription(): ?string
    {
        return __('Each figure is compared with the same stretch of the period before.');
    }

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    protected function getStats(): array
    {
        $now = $this->period()->elapsed();
        $before = $now->previous();
        $against = __('the period before');

        $attendance = app(AttendanceAnalytics::class);
        $revenue = app(RevenueAnalytics::class);
        $money = fn (int $cents): string => MembershipSetting::formatMoney(Cents::toFloat($cents));

        // Subscribers: the month the period (so far) ends in, against the one before.
        $month = $now->untilExclusive->subDay()->startOfMonth();
        $membership = app(MembershipAnalytics::class);
        $entry = AddOn::entry();

        return [
            $this->compared(__('Visits'), $attendance->arrivals($now), $attendance->arrivals($before), $against),
            $this->compared(__('Different visitors'), $attendance->uniqueVisitors($now), $attendance->uniqueVisitors($before), $against),
            $this->compared(__('First-time visitors'), $attendance->firstTimeVisitors($now), $attendance->firstTimeVisitors($before), $against)
                ->url(MemberResource::getUrl('index', ['tableFilters' => ['first_visit' => [
                    'first_visit_from' => $now->from->toDateString(),
                    'first_visit_until' => $now->untilExclusive->subDay()->toDateString(),
                ]]])),
            $this->compared(__('Revenue'), $revenue->totalCents($now), $revenue->totalCents($before), $against, $money),
            $this->compared(
                __('Regular subscribers, :month', ['month' => $month->translatedFormat('M Y')]),
                $membership->activeSubscribers($entry, $month),
                $membership->activeSubscribers($entry, $month->subMonth()),
                $month->subMonth()->translatedFormat('M Y'),
            )->url(SubscriptionResource::getUrl('index', ['tableFilters' => [
                'add_on_id' => ['value' => $entry->id],
                'covered_month' => ['month' => $month->toDateString()],
            ]])),
        ];
    }

    /**
     * A figure with how it moved, said in words and with an arrow as well as
     * a colour.
     *
     * @param  (Closure(int): string)|null  $format  how to print the figure; a plain number when null
     */
    private function compared(string $label, int $current, int $previous, string $against, ?Closure $format = null): Stat
    {
        $format ??= fn (int $value): string => (string) Number::format($value);
        $stat = Stat::make($label, $format($current));

        if ($previous === 0) {
            return $stat->description(__('Nothing in :against to compare with', ['against' => $against]));
        }

        if ($current === $previous) {
            return $stat->description(__('The same as :against', ['against' => $against]));
        }

        $replace = [
            'percent' => (int) round(abs($current - $previous) * 100 / abs($previous)),
            'against' => $against,
            'value' => $format($previous),
        ];

        return $current > $previous
            ? $stat->description(__('Up :percent% on :against (:value)', $replace))
                ->descriptionIcon(Heroicon::ArrowTrendingUp)
                ->color('success')
            : $stat->description(__('Down :percent% on :against (:value)', $replace))
                ->descriptionIcon(Heroicon::ArrowTrendingDown)
                ->color('warning');
    }
}
