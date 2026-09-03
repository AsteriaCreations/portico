<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Subscription eligibility threshold
    |--------------------------------------------------------------------------
    |
    | A member becomes eligible for either subscription plan once they've
    | attended this many events, all-time (Member::isSubscriptionEligible()). The
    | members.subscription_eligible flag is a manual override on top of this —
    | used to grandfather in long-time members whose pre-system attendance
    | history isn't reflected in the attendance table.
    |
    */

    'subscription_eligibility_threshold' => (int) env('SUBSCRIPTION_ELIGIBILITY_THRESHOLD', 5),

    /*
    |--------------------------------------------------------------------------
    | Probation period
    |--------------------------------------------------------------------------
    |
    | A member is "on probation" (reporting-only — never affects admission or
    | pricing, see AdmissionPolicy) for this many days after probation_override_start,
    | or date_vetted if that override isn't set (Member::isOnProbation()).
    |
    */

    'probation_period_days' => (int) env('PROBATION_PERIOD_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Venue capacity
    |--------------------------------------------------------------------------
    |
    | Hard cap on how many people can be in the building at once, shared
    | across all of a given night's concurrent events (CapacityService).
    | Null means capacity isn't enforced at all — the operator opts in by
    | setting VENUE_CAPACITY.
    |
    */

    'venue_capacity' => env('VENUE_CAPACITY') !== null ? (int) env('VENUE_CAPACITY') : null,

    /*
    |--------------------------------------------------------------------------
    | Default cash drawer opening float
    |--------------------------------------------------------------------------
    |
    | Pre-fills the "opening count" field when a Door+ user opens a new
    | cashbox shift (CheckIn::openShiftAction()) — purely a UI default, staff
    | still count the box and can override it. Null means nothing is
    | pre-filled.
    |
    */

    'default_opening_float' => env('DEFAULT_OPENING_FLOAT') !== null ? (float) env('DEFAULT_OPENING_FLOAT') : null,

    /*
    |--------------------------------------------------------------------------
    | Event window buffer
    |--------------------------------------------------------------------------
    |
    | Minutes of slack on either side of an event's starts_at/ends_at when
    | deciding whether it's "current" for the check-in page's event picker
    | (CheckIn::eventSelectQuery()/defaultEventId()) — lets staff pull an
    | event up a little before it officially starts and keep working it a
    | little after it officially ends, and (the reason this exists at all)
    | correctly recognizes an event that's still running past midnight, when
    | its event_date alone no longer matches "today". Only applies to events
    | with both starts_at and ends_at set; door_prepay_enabled events are
    | unaffected — they're deliberately surfaced by that flag alone, no
    | matter how far in the future their own starts_at/ends_at fall.
    |
    */

    'event_window_buffer_minutes' => (int) env('EVENT_WINDOW_BUFFER_MINUTES', 15),

];
