<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Filament\Concerns\ReadsAnalyticsPeriod;
use App\Support\AnalyticsPeriod;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Number;

/**
 * A trend chart on the Analytics page. It draws the run of weeks or months
 * ending at the page's chosen period (AnalyticsPeriod::buckets()), not just
 * the period itself, and repeats its numbers as a table for screen readers:
 * a chart is painted on a canvas, which exposes nothing to them.
 */
abstract class AnalyticsChartWidget extends ChartWidget
{
    use ReadsAnalyticsPeriod;

    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    public function getDescription(): ?string
    {
        return $this->period()->grain() === AnalyticsPeriod::GRAIN_WEEK
            ? __('By week, up to the chosen period')
            : __('By month, up to the chosen period');
    }

    public function getChartAssistiveContent(): View
    {
        return view('filament.admin.widgets.chart-data-table', [
            'caption' => $this->getHeading(),
            'data' => $this->getCachedData(),
            'format' => $this->formatChartValue(...),
        ]);
    }

    /**
     * How one plotted number reads in the screen-reader table.
     */
    protected function formatChartValue(int|float $value): string
    {
        return (string) Number::format($value);
    }

    /**
     * @return list<string>
     */
    protected function bucketLabels(): array
    {
        return array_map(fn (AnalyticsPeriod $bucket): string => $bucket->label(), $this->period()->buckets());
    }

    /**
     * One value per bucket, oldest first.
     *
     * @param  callable(AnalyticsPeriod): (int|float)  $value
     * @return list<int|float>
     */
    protected function perBucket(callable $value): array
    {
        return array_map($value, $this->period()->buckets());
    }

    /**
     * A count is a whole number, so the y-axis shouldn't offer fractional
     * ticks (Chart.js's default "nice number" step picking can land on e.g.
     * 0.5 for a low-count week). Only the precision is pinned: forcing a
     * step of 1 makes Chart.js thin the ticks to odd steps (9, 18, 27...)
     * once the counts run into the dozens.
     *
     * @return array<string, mixed>
     */
    protected function wholeNumberScale(): array
    {
        return [
            'scales' => [
                'y' => [
                    'ticks' => [
                        'precision' => 0,
                    ],
                ],
            ],
        ];
    }

    /**
     * getData() with a colour for each series. Filament draws every series
     * of a chart in the panel's one primary colour, so a stacked bar or a
     * second line can't be told apart; these are the Okabe-Ito colours,
     * which stay distinct for colour-blind viewers, in both light and dark
     * mode. A series that sets its own colour keeps it.
     *
     * @return array<string, mixed>
     */
    protected function getCachedData(): array
    {
        if ($this->cachedData !== null) {
            return $this->cachedData;
        }

        $data = $this->getData();

        foreach ($data['datasets'] ?? [] as $index => $dataset) {
            $colour = self::SERIES_COLOURS[$index % count(self::SERIES_COLOURS)];

            $data['datasets'][$index] = $dataset + [
                'borderColor' => $colour,
                'backgroundColor' => ($dataset['type'] ?? $this->getType()) === 'line' ? $colour : $colour.'CC',
                'pointBackgroundColor' => $colour,
            ];
        }

        return $this->cachedData = $data;
    }

    /** Okabe-Ito: orange, blue, bluish green, reddish purple, sky blue, vermillion. */
    private const SERIES_COLOURS = ['#E69F00', '#0072B2', '#009E73', '#CC79A7', '#56B4E9', '#D55E00'];
}
