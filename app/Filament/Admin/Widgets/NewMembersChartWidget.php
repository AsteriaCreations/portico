<?php

namespace App\Filament\Admin\Widgets;

use App\Services\Analytics\MembershipAnalytics;
use App\Support\AnalyticsPeriod;

/**
 * Member records created per week or month. After a bulk import every
 * imported member lands in the import's own week, so that bar is the import,
 * not a rush of new people.
 */
class NewMembersChartWidget extends AnalyticsChartWidget
{
    public function getHeading(): ?string
    {
        return __('New Members');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $analytics = app(MembershipAnalytics::class);

        return [
            'datasets' => [[
                'label' => __('New members'),
                'data' => $this->perBucket(fn (AnalyticsPeriod $bucket): int => $analytics->newMembers($bucket)),
            ]],
            'labels' => $this->bucketLabels(),
        ];
    }

    protected function getOptions(): array
    {
        return $this->wholeNumberScale();
    }
}
