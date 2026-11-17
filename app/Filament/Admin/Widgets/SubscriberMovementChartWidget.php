<?php

namespace App\Filament\Admin\Widgets;

use App\Services\Analytics\SubscriptionAnalytics;

/**
 * Regular subscribers per month, stacked by how they got there (new,
 * renewed, came back), with those who didn't renew as a line. Always by
 * month, whatever the Analytics page's period: a subscription is monthly.
 */
class SubscriberMovementChartWidget extends AnalyticsChartWidget
{
    protected int|string|array $columnSpan = 'full';

    public function getHeading(): ?string
    {
        return __('Subscribers month by month');
    }

    public function getDescription(): ?string
    {
        return __('By month, up to the chosen period. The bars add up to that month\'s subscribers.');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $months = app(SubscriptionAnalytics::class)->monthly($this->period());

        return [
            'datasets' => [
                ['label' => __('Renewed'), 'data' => array_column($months, 'renewed'), 'stack' => 'subscribers'],
                ['label' => __('Came back'), 'data' => array_column($months, 'returned'), 'stack' => 'subscribers'],
                ['label' => __('New'), 'data' => array_column($months, 'new'), 'stack' => 'subscribers'],
                ['label' => __('Did not renew'), 'data' => array_column($months, 'churned'), 'type' => 'line'],
            ],
            'labels' => array_column($months, 'label'),
        ];
    }

    protected function getOptions(): array
    {
        return array_replace_recursive($this->wholeNumberScale(), [
            'scales' => [
                'x' => ['stacked' => true],
                'y' => ['stacked' => true],
            ],
        ]);
    }
}
