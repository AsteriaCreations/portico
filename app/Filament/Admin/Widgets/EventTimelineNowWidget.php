<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Pages\EventTimeline;
use App\Models\Event;
use App\Services\EventTimelineService;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * "Happening now / up next" on the Dashboard for each event running now,
 * from its timeline, showing only the categories the viewer sees. Polls so
 * it moves on through the night by itself.
 */
class EventTimelineNowWidget extends Widget
{
    protected string $view = 'filament.admin.widgets.event-timeline-now-widget';

    // Not lazy, so it shows the moment the Dashboard loads (and in tests).
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null && static::eventsFor()->isNotEmpty();
    }

    /**
     * Each event running now that has something on its timeline for this
     * viewer.
     *
     * @return list<array{event: Event, now: Collection, next: Collection, url: string}>
     */
    public function getTimelines(): array
    {
        $user = auth()->user();
        $timelines = app(EventTimelineService::class);

        return static::eventsFor()
            ->map(fn (Event $event): array => $timelines->nowAndNext($event, $user) + [
                'event' => $event,
                'url' => EventTimeline::getUrl(['event' => $event->id]),
            ])
            ->filter(fn (array $timeline): bool => $timeline['now']->isNotEmpty() || $timeline['next']->isNotEmpty())
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Event>
     */
    protected static function eventsFor(): Collection
    {
        $user = auth()->user();
        $timelines = app(EventTimelineService::class);

        if ($user === null || ! $timelines->enabled()) {
            return collect();
        }

        return Event::currentQuery()
            ->whereHas('timelineItems')
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (Event $event): bool => $timelines->canView($user, $event))
            ->values();
    }
}
