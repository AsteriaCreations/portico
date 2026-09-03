<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\OccupancyAdjustment;
use Carbon\CarbonInterface;

/**
 * How many people are in the building, against a hard cap — the third
 * centralized domain decision alongside AdmissionPolicy and PricingService.
 * A prepay counts the moment its attendance row is created, not when the
 * member actually arrives, and capacity is shared across all of a given
 * night's concurrent events rather than tracked per event.
 */
class CapacityService
{
    public function occupancy(CarbonInterface $date): int
    {
        $eventIds = Event::whereDate('event_date', $date)->pluck('id');
        $attending = Attendance::whereIn('event_id', $eventIds)->whereNull('departed_at')->count();
        $adjustment = (int) OccupancyAdjustment::whereDate('for_date', $date)->sum('delta');

        return max(0, $attending + $adjustment);
    }

    public function capacity(): ?int
    {
        return MembershipSetting::current()->venue_capacity;
    }

    public function hasRoom(CarbonInterface $date): bool
    {
        return $this->capacity() === null || $this->occupancy($date) < $this->capacity();
    }
}
