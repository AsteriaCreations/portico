<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Filament\Admin\Resources\Members\MemberResource;
use App\Filament\Concerns\ReadsAnalyticsPeriod;
use App\Models\Member;
use App\Services\Analytics\SubscriptionAnalytics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * The Regular subscription in the month the Analytics page's period ends
 * in (as far as it has run), against the month before
 * (SubscriptionAnalytics::movement()), plus who could subscribe today and
 * hasn't.
 */
class SubscriptionHealthWidget extends StatsOverviewWidget
{
    use ReadsAnalyticsPeriod;

    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    /** @var array{label: string, active: int, new: int, renewed: int, returned: int, churned: int, churn_percent: ?int, in_progress: bool}|null */
    protected ?array $movement = null;

    protected function getHeading(): ?string
    {
        return __('Regular subscribers — :month', ['month' => $this->movement()['label']]);
    }

    protected function getDescription(): ?string
    {
        return $this->movement()['in_progress']
            ? __('The month is still running, so people who haven\'t renewed yet may still do so.')
            : __('Against the month before.');
    }

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    protected function getStats(): array
    {
        $movement = $this->movement();
        $analytics = app(SubscriptionAnalytics::class);

        $count = fn (int $value): string => (string) Number::format($value);

        return [
            Stat::make(__('Subscribers'), $count($movement['active'])),
            Stat::make(__('New'), $count($movement['new']))
                ->description(__('Never subscribed before')),
            Stat::make(__('Renewed'), $count($movement['renewed']))
                ->description(__('Also subscribed the month before')),
            Stat::make(__('Came back'), $count($movement['returned']))
                ->description(__('Subscribed before, but not the month before')),
            Stat::make($movement['in_progress'] ? __('Not renewed yet') : __('Did not renew'), $count($movement['churned']))
                ->description($movement['churn_percent'] === null
                    ? __('Nobody subscribed the month before')
                    : __(':percent% of the month before\'s subscribers', ['percent' => $movement['churn_percent']])),
            Stat::make(__('Can subscribe, not subscribed'), $count($analytics->eligibleNotSubscribed(Member::query())->count()))
                ->description(__('Active members, as of today'))
                ->url(MemberResource::getUrl('index', ['tableFilters' => ['eligible_not_subscribed' => ['isActive' => true]]])),
        ];
    }

    /**
     * @return array{label: string, active: int, new: int, renewed: int, returned: int, churned: int, churn_percent: ?int, in_progress: bool}
     */
    private function movement(): array
    {
        return $this->movement ??= app(SubscriptionAnalytics::class)->movement($this->period()->closingMonth());
    }
}
