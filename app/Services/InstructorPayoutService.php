<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\InstructorPayRate;

/**
 * Computes what an event's instructor is owed, per attendee, broken out by
 * how that attendee's entry was covered (Attendance::entry_covered_by) —
 * the same coverage-source axis PricingService already produces. General
 * mechanism keyed on the event's event type, not hardcoded to any one type
 * (piloted on "Yoga"). A bucket with no configured InstructorPayRate row
 * contributes $0 and is omitted from the breakdown entirely.
 */
class InstructorPayoutService
{
    public function calculate(Event $event): InstructorPayoutResult
    {
        $rates = InstructorPayRate::where('event_type_id', $event->event_type_id)->get();

        if ($rates->isEmpty()) {
            return new InstructorPayoutResult(lineItems: [], total: 0.0);
        }

        $counts = Attendance::query()
            ->where('event_id', $event->id)
            ->whereNotNull('checked_in_at')
            ->get(['entry_covered_by'])
            ->countBy(fn (Attendance $attendance) => $attendance->entry_covered_by->value);

        $lineItems = [];
        $total = 0.0;

        foreach ($rates as $rate) {
            $count = (int) ($counts[$rate->entry_covered_by->value] ?? 0);

            if ($count === 0) {
                continue;
            }

            $subtotal = $count * (float) $rate->rate;

            $lineItems[] = [
                'source' => $rate->entry_covered_by,
                'count' => $count,
                'rate' => (float) $rate->rate,
                'subtotal' => $subtotal,
            ];

            $total += $subtotal;
        }

        return new InstructorPayoutResult(lineItems: $lineItems, total: $total);
    }
}
