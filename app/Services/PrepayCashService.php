<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\PaymentMethod;
use App\Support\Cents;

/**
 * Cash paid ahead of an event's night -- at the desk for a not-yet-active
 * event, or on the event's Prepay List -- is held for that event in its own
 * envelope, outside every register box (see RegisterShiftService::
 * heldPrepayCashCents() for the box side). This is the event side: how much
 * that envelope should hold. Derived from the visits themselves, so a prepay
 * removed later (refunded from the envelope) drops out on its own.
 */
class PrepayCashService
{
    /**
     * @param  int[]  $eventIds
     */
    public function heldCashCents(array $eventIds): int
    {
        if ($eventIds === []) {
            return 0;
        }

        return Cents::of(Attendance::whereIn('event_id', $eventIds)
            ->where('prepaid_ahead', true)
            ->whereIn('payment_method', PaymentMethod::cashCodes())
            ->sum('amount_paid'));
    }

    public function heldCashForEventCents(Event $event): int
    {
        return $this->heldCashCents([$event->id]);
    }
}
