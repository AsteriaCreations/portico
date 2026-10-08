<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\InstructorPayRate;
use App\Support\Cents;
use Illuminate\Database\Eloquent\Builder;

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
    /**
     * Events on the Check-In Desk right now (Event::currentQuery()) whose
     * type pays an instructor per head. Callers check
     * instructor_payouts_enabled themselves.
     *
     * @return Builder<Event>
     */
    public function currentEventsQuery(): Builder
    {
        return Event::currentQuery()->whereHas('eventType.instructorPayRates')->orderBy('starts_at')->orderBy('name');
    }

    public function calculate(Event $event): InstructorPayoutResult
    {
        $rates = InstructorPayRate::where('event_type_id', $event->event_type_id)->get();

        if ($rates->isEmpty()) {
            return new InstructorPayoutResult(lineItems: [], totalCents: 0);
        }

        $counts = Attendance::query()
            ->where('event_id', $event->id)
            ->whereNotNull('checked_in_at')
            ->get(['entry_covered_by'])
            ->countBy(fn (Attendance $attendance) => $attendance->entry_covered_by->value);

        $lineItems = [];
        $totalCents = 0;

        foreach ($rates as $rate) {
            $count = (int) ($counts[$rate->entry_covered_by->value] ?? 0);

            if ($count === 0) {
                continue;
            }

            $rateCents = Cents::of($rate->rate);
            $subtotalCents = $count * $rateCents;

            $lineItems[] = [
                'source' => $rate->entry_covered_by,
                'count' => $count,
                'rateCents' => $rateCents,
                'subtotalCents' => $subtotalCents,
            ];

            $totalCents += $subtotalCents;
        }

        return new InstructorPayoutResult(lineItems: $lineItems, totalCents: $totalCents);
    }
}
