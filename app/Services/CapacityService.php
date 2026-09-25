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

    /**
     * Serializes admissions against the cap: two registers submitting at
     * once for different members would otherwise both count the same
     * pre-admission occupancy and both get in. Holds the single
     * membership_settings row until the caller's transaction ends.
     *
     * Must be the first read inside that transaction, and is taken even when
     * no cap is set: InnoDB fixes a REPEATABLE READ snapshot at the first
     * non-locking read, so any read ahead of this lock (including checking
     * whether a cap exists) would leave the occupancy() count that follows
     * blind to admissions committed while this one waited. For the same
     * reason it can't lazily create the settings row itself: the caller must
     * have called MembershipSetting::current() before opening the transaction.
     */
    public function lockForAdmission(): void
    {
        MembershipSetting::query()->lockForUpdate()->first();
    }
}
