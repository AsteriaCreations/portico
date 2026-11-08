<?php

namespace App\Services;

use App\Enums\AdmissionOutcome;
use App\Exceptions\PrivateRentalRefused;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\PrivateRental;
use App\Models\Room;
use App\Models\User;
use App\Support\Cents;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Booking a room for a member's private rental: the one place that prices
 * it, decides who may book, and keeps a room from being booked twice at
 * once. The desk only calls this. Each booking gets its own event, with the
 * renter as host (free entry), priced at the per-extra-person charge for
 * anyone past the people the renter paid for.
 */
class PrivateRentalService
{
    public function __construct(private AdmissionPolicy $admission) {}

    public function quote(Room $room, int $hours, int $headcount): PrivateRentalQuote
    {
        $roomCost = Cents::of($room->hourly_rate) * $hours;
        $extraPeople = max(0, $headcount - $room->included_people);
        $perExtraPerson = Cents::of($room->overage_fee) * ($room->overage_per_hour ? $hours : 1);
        $extraPeopleCost = $extraPeople * $perExtraPerson;

        return new PrivateRentalQuote(
            room: $room,
            hours: $hours,
            headcount: $headcount,
            roomCostCents: $roomCost,
            extraPeople: $extraPeople,
            perExtraPersonCents: $perExtraPerson,
            extraPeopleCostCents: $extraPeopleCost,
            totalCents: $roomCost + $extraPeopleCost,
        );
    }

    /**
     * Why $member can't book a rental on $date, or null when they can. The
     * "No private rentals" flag, or anything that would stop them getting
     * in at all (banned, deceased, under the age of majority on that date).
     * The flag's reason is Manager+ only, so it never appears here.
     */
    public function refusalFor(Member $member, CarbonInterface $date): ?string
    {
        if ($member->rentals_blocked) {
            return __('This member can\'t book private rentals.');
        }

        $decision = $this->admission->decide($member, new Event(['event_date' => $date->toDateString()]));
        if ($decision->outcome === AdmissionOutcome::Block) {
            return __('This member can\'t book a private rental: :reason', ['reason' => $decision->message]);
        }

        return null;
    }

    /**
     * A booking that already holds $room for part of $startsAt–$endsAt, if
     * any. Back-to-back bookings (one ends as the next starts) don't clash.
     */
    public function conflictFor(Room $room, CarbonInterface $startsAt, CarbonInterface $endsAt, ?PrivateRental $ignoring = null): ?PrivateRental
    {
        return PrivateRental::query()
            ->notCancelled()
            ->where('room_id', $room->id)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->when($ignoring, fn ($query) => $query->whereKeyNot($ignoring->getKey()))
            ->orderBy('starts_at')
            ->first();
    }

    /**
     * @throws PrivateRentalRefused
     */
    public function book(Member $member, Room $room, CarbonInterface $startsAt, int $hours, int $headcount, User $bookedBy): PrivateRental
    {
        abort_unless(MembershipSetting::current()->private_rentals_enabled, 403);

        $startsAt = Carbon::instance($startsAt)->startOfMinute();
        $endsAt = $startsAt->copy()->addHours($hours);

        if (! $room->active) {
            throw new PrivateRentalRefused(__(':room isn\'t taking bookings.', ['room' => $room->name]));
        }
        if ($hours < $room->min_hours || $hours > $room->max_hours) {
            throw new PrivateRentalRefused(__(':room can be booked for :min to :max hours.', ['room' => $room->name, 'min' => $room->min_hours, 'max' => $room->max_hours]));
        }
        if ($headcount < 1) {
            throw new PrivateRentalRefused(__('Enter how many people are coming.'));
        }
        if ($startsAt->lt(today())) {
            throw new PrivateRentalRefused(__('A private rental can\'t be booked for a past date.'));
        }
        if ($endsAt->lte(now())) {
            throw new PrivateRentalRefused(__('That booking would already be over.'));
        }
        if ($refusal = $this->refusalFor($member, $startsAt)) {
            throw new PrivateRentalRefused($refusal);
        }

        $quote = $this->quote($room, $hours, $headcount);

        return DB::transaction(function () use ($member, $room, $startsAt, $endsAt, $quote, $bookedBy): PrivateRental {
            // Serializes bookings of this room, so two desks can't both pass
            // the overlap check for the same slot.
            Room::whereKey($room->id)->lockForUpdate()->first();

            if ($conflict = $this->conflictFor($room, $startsAt, $endsAt)) {
                throw new PrivateRentalRefused(__(':room is already booked :from–:to.', [
                    'room' => $room->name,
                    'from' => $conflict->starts_at->isoFormat('lll'),
                    'to' => $conflict->ends_at->isoFormat('LT'),
                ]));
            }

            $event = Event::create([
                'event_date' => $startsAt->toDateString(),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'name' => __('Private rental — :room', ['room' => $room->name]),
                'entry_fee' => Cents::toDecimal($quote->perExtraPersonCents),
                'pool_fee' => 0,
                'door_prepay_enabled' => false,
                'host_id' => $member->id,
                'created_by' => $bookedBy->id,
            ]);

            return PrivateRental::create([
                'room_id' => $room->id,
                'member_id' => $member->id,
                'event_id' => $event->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                ...$quote->toRentalAttributes(),
                'booked_by' => $bookedBy->id,
            ]);
        });
    }
}
