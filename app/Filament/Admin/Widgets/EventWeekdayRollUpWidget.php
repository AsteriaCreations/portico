<?php

namespace App\Filament\Admin\Widgets;

use App\Services\Analytics\EventAnalytics;
use Illuminate\Support\Collection;

class EventWeekdayRollUpWidget extends EventRollUpWidget
{
    protected function getHeading(): ?string
    {
        return __('Average night by day of the week — :period', ['period' => $this->period()->label()]);
    }

    protected function groups(): Collection
    {
        return app(EventAnalytics::class)->byWeekday($this->period());
    }
}
