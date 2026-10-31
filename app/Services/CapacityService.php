<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\OccupancyAdjustment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

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

    /**
     * Who has actually arrived and not left: occupancy() without the
     * prepays still on their way. This is the "in the building" headcount
     * staff see; occupancy() stays the number the cap is enforced against,
     * because a prepay holds its spot and arriving is never capacity-blocked.
     */
    public function inBuilding(CarbonInterface $date): int
    {
        $eventIds = Event::whereDate('event_date', $date)->pluck('id');
        $arrived = Attendance::whereIn('event_id', $eventIds)->whereNotNull('checked_in_at')->whereNull('departed_at')->count();
        $adjustment = (int) OccupancyAdjustment::whereDate('for_date', $date)->sum('delta');

        return max(0, $arrived + $adjustment);
    }

    /**
     * Prepays for $date's events that haven't arrived yet -- counted in
     * occupancy() but not inBuilding().
     */
    public function awaitingArrival(CarbonInterface $date): int
    {
        $eventIds = Event::whereDate('event_date', $date)->pluck('id');

        return Attendance::whereIn('event_id', $eventIds)->whereNull('checked_in_at')->whereNull('departed_at')->count();
    }

    /**
     * Anonymous departures (RecordDeparturesWidget) recorded for $date that
     * haven't yet been matched to a named patron -- the net negative of that
     * night's occupancy_adjustments, floored at zero.
     */
    public function unmatchedDepartures(CarbonInterface $date): int
    {
        return max(0, -(int) OccupancyAdjustment::whereDate('for_date', $date)->sum('delta'));
    }

    /**
     * Departs a patron who already left through an anonymous "N people left"
     * headcount, so occupancy doesn't drop twice: sets departed_at (the
     * patron leaves Active Patrons) and writes a +1 adjustment on the
     * event's own night that cancels it in occupancy(). Only allowed while
     * that night still has an unmatched anonymous departure to absorb it,
     * so it can never push the count above what the desk recorded.
     */
    public function departAlreadyCounted(Attendance $attendance, User $staff): void
    {
        $date = $attendance->event->event_date;
        MembershipSetting::current();

        DB::transaction(function () use ($attendance, $staff, $date): void {
            $this->lockForAdmission();

            abort_unless($this->unmatchedDepartures($date) > 0, 422, 'No anonymous departure is left to match on that night.');
            abort_unless($attendance->refresh()->departed_at === null, 422, 'That patron is already departed.');

            $attendance->update(['departed_at' => now()]);

            OccupancyAdjustment::create([
                'for_date' => $date,
                'delta' => 1,
                'reason' => "Matched to {$attendance->member->username} on Active Patrons (already counted in Record departures)",
                'recorded_by' => $staff->id,
            ]);
        });
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
