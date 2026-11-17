<?php

namespace App\Filament\Admin\Widgets;

use App\Models\MembershipSetting;
use App\Services\Analytics\DoorFlowAnalytics;
use Carbon\CarbonImmutable;

/**
 * Each of the Analytics page's nights' peak (DoorFlowAnalytics::nights()),
 * with the venue capacity as a line when one is set. One bar per night of
 * the period itself, not the trailing weeks or months the other trends use.
 */
class PeakOccupancyChartWidget extends AnalyticsChartWidget
{
    public function getHeading(): ?string
    {
        return __('Most inside at once, night by night');
    }

    public function getDescription(): ?string
    {
        return __('Each night of the chosen period.');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $nights = app(DoorFlowAnalytics::class)->nights($this->period());
        $capacity = MembershipSetting::current()->venue_capacity;

        $datasets = [['label' => __('Most inside'), 'data' => array_column($nights, 'peak')]];

        if ($capacity !== null && $nights !== []) {
            $datasets[] = ['label' => __('Capacity'), 'data' => array_fill(0, count($nights), $capacity), 'type' => 'line'];
        }

        return [
            'datasets' => $datasets,
            'labels' => array_map(fn (array $night): string => CarbonImmutable::parse($night['date'])->isoFormat('ll'), $nights),
        ];
    }

    protected function getOptions(): array
    {
        return $this->wholeNumberScale();
    }
}
