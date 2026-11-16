<?php

namespace App\Filament\Concerns;

use App\Support\AnalyticsPeriod;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * For a widget on the Analytics page: the period chosen at the top of the
 * page. Rendered anywhere else (or on its own in a test) there are no page
 * filters, and it reads the default period.
 */
trait ReadsAnalyticsPeriod
{
    use InteractsWithPageFilters;

    protected function period(): AnalyticsPeriod
    {
        return AnalyticsPeriod::fromFilters($this->pageFilters);
    }
}
