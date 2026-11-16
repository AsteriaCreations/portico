<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Event;
use App\Models\EventTimelineCategory;
use App\Models\EventTimelineItem;
use App\Models\MembershipSetting;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * An event's run of show, in one place: who may edit it (the event's own
 * showrunner while it's current or upcoming, and Admin+ for any event),
 * who sees which category, the rule that every item sits inside the
 * event's starts_at..ends_at, and the day-grid layout. Pages call this;
 * they never re-implement any of it.
 */
class EventTimelineService
{
    /** The grid's click-to-add step, and the shortest a block is drawn. */
    public const SLOT_MINUTES = 15;

    public function enabled(): bool
    {
        return MembershipSetting::current()->event_timelines_enabled;
    }

    /**
     * Whether $user is the event's assigned showrunner (through their
     * linked member record), whatever their role.
     */
    public function isShowrunnerOf(User $user, Event $event): bool
    {
        return $user->member_id !== null
            && $event->showrunner_id !== null
            && (int) $event->showrunner_id === (int) $user->member_id;
    }

    public function canEdit(User $user, Event $event): bool
    {
        if (! $this->enabled() || ! $user->active || $event->isArchived()) {
            return false;
        }

        if ($user->role->atLeast(Role::Admin)) {
            return true;
        }

        return $this->isShowrunnerOf($user, $event) && $event->isCurrentOrFuture();
    }

    /**
     * Whether $user may read $event's timeline (the categories they see):
     * anyone who can edit it, and Volunteer+ for a current or upcoming
     * event.
     */
    public function canView(User $user, Event $event): bool
    {
        if ($this->canEdit($user, $event)) {
            return true;
        }

        return $this->enabled() && $user->active && ! $event->isArchived()
            && $user->role->atLeast(Role::Volunteer) && $event->isCurrentOrFuture();
    }

    /**
     * Whether $user may open the Event timeline page at all.
     */
    public function canAccessPage(User $user): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        return $user->role->atLeast(Role::Volunteer)
            || $user->role === Role::Showrunner
            || ($user->member_id !== null && Event::currentOrFutureQuery()->where('showrunner_id', $user->member_id)->exists());
    }

    /**
     * The events $user may pick to read: every current or upcoming one for
     * Volunteer+, else the ones they can edit.
     *
     * @return Builder<Event>
     */
    public function viewableEventsQuery(User $user): Builder
    {
        return $user->role->atLeast(Role::Volunteer)
            ? Event::currentOrFutureQuery()
            : $this->editableEventsQuery($user);
    }

    /**
     * What's on now and what's next on $event for $user: "now" is a span
     * under way, or a moment from the last SLOT_MINUTES; "next" is the
     * soonest $limit items still to start.
     *
     * @return array{now: Collection<int, EventTimelineItem>, next: Collection<int, EventTimelineItem>}
     */
    public function nowAndNext(Event $event, ?User $user, ?CarbonInterface $at = null, int $limit = 3): array
    {
        $at ??= now();
        $items = $this->itemsFor($event, $user);

        return [
            'now' => $items->filter(fn (EventTimelineItem $item): bool => $item->starts_at->lte($at)
                && ($item->ends_at ? $item->ends_at->gt($at) : $item->starts_at->gt($at->copy()->subMinutes(self::SLOT_MINUTES))))->values(),
            'next' => $items->filter(fn (EventTimelineItem $item): bool => $item->starts_at->gt($at))->take($limit)->values(),
        ];
    }

    /**
     * The events $user may pick to edit: current or upcoming, theirs unless
     * Admin+. A past event stays reachable for Admin+ through its own page's
     * Timeline link.
     *
     * @return Builder<Event>
     */
    public function editableEventsQuery(User $user): Builder
    {
        return Event::currentOrFutureQuery()
            ->when(! $user->role->atLeast(Role::Admin), fn (Builder $query) => $query->where('showrunner_id', $user->member_id ?? 0));
    }

    /**
     * The categories $user sees on $event. Nobody signed in (the printed
     * sheet, the display screen) sees public ones only; the event's
     * showrunner and Admin+ see every one; anyone else sees public ones plus
     * those whose min_role they meet.
     *
     * @return Builder<EventTimelineCategory>
     */
    public function visibleCategoriesQuery(?User $user, ?Event $event = null): Builder
    {
        $query = EventTimelineCategory::query()->orderBy('sort_order')->orderBy('name');

        if ($user === null) {
            return $query->where('is_public', true);
        }

        if ($user->role->atLeast(Role::Admin) || ($event && $this->isShowrunnerOf($user, $event))) {
            return $query;
        }

        $roles = array_values(array_filter(Role::cases(), fn (Role $role): bool => $user->role->atLeast($role)));

        return $query->where(fn (Builder $query) => $query
            ->where('is_public', true)
            ->orWhereIn('min_role', array_map(fn (Role $role): string => $role->value, $roles)));
    }

    /**
     * @return Collection<int, EventTimelineItem>
     */
    public function itemsFor(Event $event, ?User $user): Collection
    {
        return $event->timelineItems()
            ->whereIn('event_timeline_category_id', $this->visibleCategoriesQuery($user, $event)->select('id'))
            ->with('category')
            ->get();
    }

    /**
     * Why these times can't go on this event's timeline, or null if they can.
     */
    public function timeError(Event $event, ?CarbonInterface $startsAt, ?CarbonInterface $endsAt): ?string
    {
        if (! $event->starts_at || ! $event->ends_at) {
            return __('This event has no start and end time, so it can\'t have a timeline. Set them on the event first.');
        }

        if ($startsAt === null) {
            return __('Choose when it starts.');
        }

        if ($startsAt->lt($event->starts_at) || $startsAt->gt($event->ends_at)) {
            return __('It has to start between the event\'s start (:start) and end (:end).', [
                'start' => $event->starts_at->isoFormat('lll'),
                'end' => $event->ends_at->isoFormat('lll'),
            ]);
        }

        if ($endsAt !== null && $endsAt->lte($startsAt)) {
            return __('It has to end after it starts.');
        }

        if ($endsAt !== null && $endsAt->gt($event->ends_at)) {
            return __('It has to end by the event\'s end (:end).', ['end' => $event->ends_at->isoFormat('lll')]);
        }

        return null;
    }

    /**
     * Adds an item, or changes $item. Every rule is checked here again, so a
     * forged call gets nowhere: the page's own checks are only for the form.
     *
     * @param  array{event_timeline_category_id: mixed, title: string, description?: ?string, starts_at: mixed, ends_at?: mixed}  $data
     */
    public function save(User $user, Event $event, array $data, ?EventTimelineItem $item = null): EventTimelineItem
    {
        abort_unless($this->canEdit($user, $event), 403);
        abort_if($item !== null && (int) $item->event_id !== (int) $event->id, 403);

        $startsAt = filled($data['starts_at'] ?? null) ? Carbon::parse($data['starts_at']) : null;
        $endsAt = filled($data['ends_at'] ?? null) ? Carbon::parse($data['ends_at']) : null;

        if ($error = $this->timeError($event, $startsAt, $endsAt)) {
            throw ValidationException::withMessages(['starts_at' => $error]);
        }

        if (! EventTimelineCategory::whereKey($data['event_timeline_category_id'] ?? 0)->exists()) {
            throw ValidationException::withMessages(['event_timeline_category_id' => __('Choose a category.')]);
        }

        $item ??= new EventTimelineItem(['event_id' => $event->id, 'created_by' => $user->id]);
        $item->fill([
            'event_timeline_category_id' => $data['event_timeline_category_id'],
            'title' => $data['title'],
            'description' => filled($data['description'] ?? null) ? $data['description'] : null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'updated_by' => $user->id,
        ])->save();

        return $item;
    }

    public function delete(User $user, EventTimelineItem $item): void
    {
        abort_unless($this->canEdit($user, $item->event), 403);

        $item->delete();
    }

    /**
     * The event's day grid: an hour line per hour, a click-to-add slot every
     * SLOT_MINUTES, and each item as a block placed by percentage of the
     * event's length. Overlapping items share the width side by side, in
     * as many lanes as their busiest moment needs.
     *
     * @param  Collection<int, EventTimelineItem>  $items
     * @return array{slotCount: int, hours: list<array{label: string, top: float}>, slots: list<array{at: string, label: string, top: float}>, blocks: list<array{item: EventTimelineItem, top: float, height: float, lane: int, lanes: int, time: string}>}|null
     */
    public function layout(Event $event, Collection $items): ?array
    {
        if (! $event->starts_at || ! $event->ends_at || $event->ends_at->lte($event->starts_at)) {
            return null;
        }

        $start = $event->starts_at->copy();
        $total = $start->diffInMinutes($event->ends_at);
        $percent = fn (CarbonInterface $at): float => round(max(0, min($total, $start->diffInMinutes($at))) / $total * 100, 4);

        $hours = [];
        for ($at = $start->copy()->ceilHour(); $at->lte($event->ends_at); $at->addHour()) {
            $hours[] = ['label' => $at->isoFormat('LT'), 'top' => $percent($at)];
        }

        $slots = [];
        for ($at = $start->copy(); $at->lt($event->ends_at); $at->addMinutes(self::SLOT_MINUTES)) {
            $slots[] = ['at' => $at->format('Y-m-d H:i:s'), 'label' => $at->isoFormat('LT'), 'top' => $percent($at)];
        }

        $blocks = [];
        $group = [];
        $groupEnd = null;
        $laneEnds = [];

        $flush = function () use (&$blocks, &$group, &$laneEnds): void {
            $lanes = max(1, count($laneEnds));
            foreach ($group as $block) {
                $blocks[] = $block + ['lanes' => $lanes];
            }
            $group = [];
            $laneEnds = [];
        };

        foreach ($items->sortBy('starts_at')->values() as $item) {
            $from = $item->starts_at;
            $drawnTo = $from->copy()->addMinutes(max(self::SLOT_MINUTES, $item->ends_at ? $from->diffInMinutes($item->ends_at) : 0));

            if ($groupEnd !== null && $from->gte($groupEnd)) {
                $flush();
                $groupEnd = null;
            }

            $lane = 0;
            while (isset($laneEnds[$lane]) && $laneEnds[$lane]->gt($from)) {
                $lane++;
            }
            $laneEnds[$lane] = $drawnTo;
            $groupEnd = $groupEnd === null || $drawnTo->gt($groupEnd) ? $drawnTo : $groupEnd;

            $top = $percent($from);
            $group[] = [
                'item' => $item,
                'top' => $top,
                'height' => max(0.5, $percent($drawnTo) - $top),
                'lane' => $lane,
                'time' => $item->ends_at
                    ? __(':start – :end', ['start' => $from->isoFormat('LT'), 'end' => $item->ends_at->isoFormat('LT')])
                    : $from->isoFormat('LT'),
            ];
        }
        $flush();

        return [
            'slotCount' => count($slots),
            'hours' => $hours,
            'slots' => $slots,
            'blocks' => $blocks,
        ];
    }
}
