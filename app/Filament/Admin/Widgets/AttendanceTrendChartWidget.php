<?php

namespace App\Filament\Admin\Widgets;

use App\Services\Analytics\AttendanceAnalytics;
use App\Support\AnalyticsPeriod;

/**
 * Arrivals per week or month. A prepay that never arrived isn't one.
 */
class AttendanceTrendChartWidget extends AnalyticsChartWidget
{
    public function getHeading(): ?string
    {
        return __('Attendance trend');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $analytics = app(AttendanceAnalytics::class);

        return [
            'datasets' => [[
                'label' => __('Attendance'),
                'data' => $this->perBucket(fn (AnalyticsPeriod $bucket): int => $analytics->arrivals($bucket)),
            ]],
            'labels' => $this->bucketLabels(),
        ];
    }

    protected function getOptions(): array
    {
        return $this->wholeNumberScale();
    }
}
