<?php

namespace App\Filament\Admin\Widgets;

use App\Services\Analytics\DoorFlowAnalytics;

/**
 * When people arrive: the average number checking in in each hour of the
 * night, over the Analytics page's nights (DoorFlowAnalytics::arrivalsByHour()).
 */
class ArrivalTimesChartWidget extends AnalyticsChartWidget
{
    public function getHeading(): ?string
    {
        return __('When people arrive');
    }

    public function getDescription(): ?string
    {
        return __('Average check-ins in each hour, over the nights of the chosen period.');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $hours = app(DoorFlowAnalytics::class)->arrivalsByHour($this->period());

        return [
            'datasets' => [['label' => __('Average arrivals'), 'data' => array_column($hours, 'average')]],
            'labels' => array_column($hours, 'label'),
        ];
    }
}
