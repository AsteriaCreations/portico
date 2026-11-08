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

    /**
     * A new booking's price, from the room's prices today.
     */
    public function quote(Room $room, int $hours, int $headcount): PrivateRentalQuote
    {
        return $this->price(Cents::of($room->hourly_rate), $room->included_people, Cents::of($room->overage_fee), $room->overage_per_hour, $hours, $headcount);
    }

    /**
     * A changed booking's price, from the prices it was booked at, so
     * changing the hours or headcount never picks up a later room price.
     */
    public function requote(PrivateRental $rental, int $hours, int $headcount): PrivateRentalQuote
    {
        return $this->price(Cents::of($rental->hourly_rate), $rental->included_people, Cents::of($rental->overage_fee), $rental->overage_per_hour, $hours, $headcount);
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
     * Whether a booking can still be changed or cancelled: not cancelled,
     * and not yet over.
     */
    public function isChangeable(PrivateRental $rental): bool
    {
        return $rental->cancelled_at === null && $rental->ends_at->isFuture();
    }

    /**
     * @throws PrivateRentalRefused
     */
    public function book(Member $member, Room $room, CarbonInterface $startsAt, int $hours, int $headcount, User $bookedBy): PrivateRental
    {
        abort_unless(MembershipSetting::current()->private_rentals_enabled, 403);

        if (! $room->active) {
            throw new PrivateRentalRefused(__(':room isn\'t taking bookings.', ['room' => $room->name]));
        }

        [$startsAt, $endsAt] = $this->validateSlot($member, $room, $startsAt, $hours, $headcount);
        $quote = $this->quote($room, $hours, $headcount);

        return DB::transaction(function () use ($member, $room, $startsAt, $endsAt, $quote, $bookedBy): PrivateRental {
            $this->lockRoomAndRefuseClash($room, $startsAt, $endsAt);

            $event = Event::create([
                ...$this->eventTimes($startsAt, $endsAt, $quote),
                'name' => __('Private rental — :room', ['room' => $room->name]),
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

    /**
     * Moves a booking to a new date/start time, or changes its hours or
     * headcount, re-priced at the rates it was booked at. Its event follows.
     * Same checks as booking, except the room may since have been turned
     * off: the booking already holds it.
     *
     * @throws PrivateRentalRefused
     */
    public function change(PrivateRental $rental, CarbonInterface $startsAt, int $hours, int $headcount): PrivateRental
    {
        abort_unless(MembershipSetting::current()->private_rentals_enabled, 403);

        if (! $this->isChangeable($rental)) {
            throw new PrivateRentalRefused(__('This booking can no longer be changed.'));
        }

        [$startsAt, $endsAt] = $this->validateSlot($rental->member, $rental->room, $startsAt, $hours, $headcount);
        $quote = $this->requote($rental, $hours, $headcount);

        return DB::transaction(function () use ($rental, $startsAt, $endsAt, $quote): PrivateRental {
            $this->lockRoomAndRefuseClash($rental->room, $startsAt, $endsAt, $rental);

            $rental->event->forceFill([
                ...$this->eventTimes($startsAt, $endsAt, $quote),
                'checkin_closes_at' => Event::defaultCheckInClose($startsAt, $endsAt),
            ])->save();

            $rental->update([
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                ...$quote->toRentalAttributes(),
            ]);

            return $rental;
        });
    }

    /**
     * Frees the room and archives the booking's event, so it drops off the
     * desk. Refused once anyone has been checked in to it.
     *
     * @throws PrivateRentalRefused
     */
    public function cancel(PrivateRental $rental, User $by): PrivateRental
    {
        abort_unless(MembershipSetting::current()->private_rentals_enabled, 403);

        if (! $this->isChangeable($rental)) {
            throw new PrivateRentalRefused(__('This booking can no longer be cancelled.'));
        }
        if ($rental->event->attendance()->exists()) {
            throw new PrivateRentalRefused(__('Someone is already checked in to this rental, so it can\'t be cancelled.'));
        }

        return DB::transaction(function () use ($rental, $by): PrivateRental {
            $rental->forceFill(['cancelled_at' => now(), 'cancelled_by' => $by->id])->save();
            $rental->event->archive($by);

            return $rental;
        });
    }

    private function price(int $hourlyRate, int $includedPeople, int $overageFee, bool $overagePerHour, int $hours, int $headcount): PrivateRentalQuote
    {
        $roomCost = $hourlyRate * $hours;
        $extraPeople = max(0, $headcount - $includedPeople);
        $perExtraPerson = $overageFee * ($overagePerHour ? $hours : 1);
        $extraPeopleCost = $extraPeople * $perExtraPerson;

        return new PrivateRentalQuote(
            hourlyRateCents: $hourlyRate,
            includedPeople: $includedPeople,
            overageFeeCents: $overageFee,
            overagePerHour: $overagePerHour,
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
     * @return array{0: Carbon, 1: Carbon} the start (to the minute) and end
     *
     * @throws PrivateRentalRefused
     */
    private function validateSlot(Member $member, Room $room, CarbonInterface $startsAt, int $hours, int $headcount): array
    {
        $startsAt = Carbon::instance($startsAt)->startOfMinute();
        $endsAt = $startsAt->copy()->addHours($hours);

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

        return [$startsAt, $endsAt];
    }

    /**
     * Serializes bookings of this room, so two desks can't both pass the
     * overlap check for the same slot. Call inside the write's transaction.
     *
     * @throws PrivateRentalRefused
     */
    private function lockRoomAndRefuseClash(Room $room, CarbonInterface $startsAt, CarbonInterface $endsAt, ?PrivateRental $ignoring = null): void
    {
        Room::whereKey($room->id)->lockForUpdate()->first();

        if ($conflict = $this->conflictFor($room, $startsAt, $endsAt, $ignoring)) {
            throw new PrivateRentalRefused(__(':room is already booked :from–:to.', [
                'room' => $room->name,
                'from' => $conflict->starts_at->isoFormat('lll'),
                'to' => $conflict->ends_at->isoFormat('LT'),
            ]));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function eventTimes(CarbonInterface $startsAt, CarbonInterface $endsAt, PrivateRentalQuote $quote): array
    {
        return [
            'event_date' => $startsAt->toDateString(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'entry_fee' => Cents::toDecimal($quote->perExtraPersonCents),
        ];
    }
}
