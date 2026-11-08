<?php

namespace App\Services;

use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\ShiftSlot;

/**
 * Copies an event type's shift templates onto an event as its shift slots.
 * Runs when an event is created (form, duplicate, repeat, bulk upload) while
 * staff scheduling is on, and from the event's Shifts tab ("Add shifts from
 * the template"). A template shift the event already has (same role, start
 * and end) is skipped, so running it again only adds what's missing. An
 * event with no start time gets none: there's nothing to count from.
 */
class ShiftSlotService
{
    /**
     * Returns how many slots it added.
     */
    public function fillFromTemplate(Event $event): int
    {
        if ($event->starts_at === null) {
            return 0;
        }

        $existing = $event->shiftSlots()->get()
            ->map(fn (ShiftSlot $slot): string => $this->key($slot->shift_role_id, $slot->starts_at, $slot->ends_at))
            ->all();
        $added = 0;

        foreach ($event->eventType?->shiftTemplates()->whereRelation('shiftRole', 'active', true)->get() ?? [] as $template) {
            $startsAt = $event->starts_at->copy()->addMinutes($template->start_offset_minutes);
            $endsAt = $startsAt->copy()->addMinutes($template->duration_minutes);

            if (in_array($this->key($template->shift_role_id, $startsAt, $endsAt), $existing, true)) {
                continue;
            }

            $event->shiftSlots()->create([
                'shift_role_id' => $template->shift_role_id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'headcount' => $template->headcount,
            ]);
            $added++;
        }

        return $added;
    }

    /**
     * The created hook: only while staff scheduling is on.
     */
    public function fillNewEvent(Event $event): void
    {
        if (MembershipSetting::current()->staff_scheduling_enabled) {
            $this->fillFromTemplate($event);
        }
    }

    protected function key(int $roleId, \DateTimeInterface $startsAt, \DateTimeInterface $endsAt): string
    {
        return $roleId.'|'.$startsAt->format('Y-m-d H:i').'|'.$endsAt->format('Y-m-d H:i');
    }
}
