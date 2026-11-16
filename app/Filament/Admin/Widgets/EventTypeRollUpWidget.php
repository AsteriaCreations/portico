<?php

namespace App\Filament\Admin\Widgets;

use App\Services\Analytics\EventAnalytics;
use Illuminate\Support\Collection;

class EventTypeRollUpWidget extends EventRollUpWidget
{
    protected function getHeading(): ?string
    {
        return __('Average night by event type — :period', ['period' => $this->period()->label()]);
    }

    protected function groups(): Collection
    {
        return app(EventAnalytics::class)->byEventType($this->period());
    }
}
