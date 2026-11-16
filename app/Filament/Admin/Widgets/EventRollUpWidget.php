<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Filament\Concerns\ReadsAnalyticsPeriod;
use App\Models\MembershipSetting;
use App\Support\Cents;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;

/**
 * The Analytics page's events averaged by some grouping (EventAnalytics):
 * how many a night, and what a night and a head bring in.
 */
abstract class EventRollUpWidget extends StatsOverviewWidget
{
    use ReadsAnalyticsPeriod;

    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    /**
     * @return Collection<int, array{name: string, events: int, average_headcount: int, average_revenue_cents: int, per_head_cents: ?int}>
     */
    abstract protected function groups(): Collection;

    protected function getStats(): array
    {
        $money = fn (int $cents): string => MembershipSetting::formatMoney(Cents::toFloat($cents));

        return $this->groups()
            ->map(fn (array $group) => Stat::make(
                trans_choice(':name · :count event|:name · :count events', $group['events'], ['name' => $group['name']]),
                __(':count a night', ['count' => $group['average_headcount']]),
            )->description($group['per_head_cents'] === null
                ? __(':amount a night', ['amount' => $money($group['average_revenue_cents'])])
                : __(':amount a night · :head a head', [
                    'amount' => $money($group['average_revenue_cents']),
                    'head' => $money($group['per_head_cents']),
                ])))
            ->all();
    }
}
