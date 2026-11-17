<?php

namespace App\Filament\Admin\Widgets;

use App\Models\MembershipSetting;
use App\Services\Analytics\StaffAnalytics;
use App\Support\AnalyticsPeriod;

/**
 * Conduct records per week or month (StaffAnalytics::conduct()), up to the
 * Analytics page's period.
 */
class ConductTrendChartWidget extends AnalyticsChartWidget
{
    public function getHeading(): ?string
    {
        return __('Conduct trend');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $analytics = app(StaffAnalytics::class);
        $buckets = array_map(fn (AnalyticsPeriod $bucket): array => $analytics->conduct($bucket), $this->period()->buckets());

        $datasets = [];

        if (MembershipSetting::current()->behavior_notes_enabled || array_sum(array_column($buckets, 'notes')) > 0) {
            $datasets[] = [
                'label' => __('Behavior notes'),
                'data' => array_map(fn (array $conduct): int => $conduct['notes'] - $conduct['retracted'], $buckets),
            ];
        }

        $datasets[] = ['label' => __('Banned'), 'data' => array_column($buckets, 'banned')];
        $datasets[] = ['label' => __('Put on the watchlist'), 'data' => array_column($buckets, 'watchlisted')];

        return [
            'datasets' => $datasets,
            'labels' => $this->bucketLabels(),
        ];
    }

    protected function getOptions(): array
    {
        return $this->wholeNumberScale();
    }
}
