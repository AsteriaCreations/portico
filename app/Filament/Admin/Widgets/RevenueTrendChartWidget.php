<?php

namespace App\Filament\Admin\Widgets;

use App\Models\MembershipSetting;
use App\Services\Analytics\RevenueAnalytics;
use App\Support\AnalyticsPeriod;
use App\Support\Cents;

/**
 * Revenue per week or month, stacked by stream: Event, Subscription, Rental
 * (only for a club that uses private rentals) and Other. The streams are
 * RevenueAnalytics::streams(), which mirror a register shift's own breakdown.
 */
class RevenueTrendChartWidget extends AnalyticsChartWidget
{
    public function getHeading(): ?string
    {
        return __('Revenue trend');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $analytics = app(RevenueAnalytics::class);
        $buckets = array_map(fn (AnalyticsPeriod $bucket): array => $analytics->streams($bucket), $this->period()->buckets());

        $labels = [
            'event' => __('Event'),
            'subscription' => __('Subscription'),
            'rental' => __('Rental'),
            'other' => __('Other'),
        ];

        return [
            'datasets' => collect(array_keys($buckets[0]))
                ->map(fn (string $stream): array => [
                    'label' => $labels[$stream],
                    'data' => array_map(fn (array $streams): float => Cents::toFloat($streams[$stream]), $buckets),
                ])
                ->all(),
            'labels' => $this->bucketLabels(),
        ];
    }

    protected function formatChartValue(int|float $value): string
    {
        return MembershipSetting::formatMoney($value);
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['stacked' => true],
                'y' => ['stacked' => true],
            ],
        ];
    }
}
