<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Event;

/**
 * Backs both NotifyEventEnded's email/notification content and EventForm's
 * read-only summary section, so the two surfaces can never drift apart —
 * same "one place, every caller reads it" principle as AdmissionPolicy and
 * PricingService.
 */
class EventSummaryService
{
    /**
     * @return array{checked_in: int, prepaid_no_show: int, revenue: float}
     */
    public function forEvent(Event $event): array
    {
        $attendance = Attendance::where('event_id', $event->id);

        return [
            'checked_in' => (clone $attendance)->whereNotNull('checked_in_at')->count(),
            // Once the event has ended, a row with no arrival is just a
            // no-show — the same "prepaid, awaiting arrival" state the
            // check-in page shows live, read here after the fact.
            'prepaid_no_show' => (clone $attendance)->whereNull('checked_in_at')->count(),
            'revenue' => (float) (clone $attendance)->sum('amount_paid'),
        ];
    }
}
