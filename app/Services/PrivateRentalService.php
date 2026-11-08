<?php

namespace App\Services;

use App\Enums\AdmissionOutcome;
use App\Exceptions\PrivateRentalRefused;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\PaymentMethod;
use App\Models\PrivateRental;
use App\Models\PrivateRentalPayment;
use App\Models\RegisterShift;
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
     * What's been paid for a booking, less refunds.
     */
    public function netPaidCents(PrivateRental $rental): int
    {
        return Cents::of($rental->payments()->sum('amount'));
    }

    /**
     * Paid bookings are locked: no Change, and only a Manager+ cancel with
     * refund (refundAndCancel()).
     */
    public function isPaid(PrivateRental $rental): bool
    {
        return $this->netPaidCents($rental) > 0;
    }

    /**
     * Payment is taken at the start of the rental: from its day until it
     * ends, once, while it isn't cancelled. Not ahead of its day, so the
     * money always goes in that night's box.
     */
    public function canCollectPayment(PrivateRental $rental): bool
    {
        return $this->isChangeable($rental)
            && $rental->starts_at->lte(today()->endOfDay())
            && Cents::of($rental->total) > 0
            && ! $this->isPaid($rental);
    }

    /**
     * Takes the booking's total, plus the method's transaction fee, as one
     * payment row on $shift (the desk's open box, if any). Cash needs an
     * open box. Only the desk's own methods are accepted.
     *
     * @throws PrivateRentalRefused
     */
    public function collectPayment(PrivateRental $rental, string $paymentMethod, ?RegisterShift $shift, User $by): PrivateRentalPayment
    {
        abort_unless(MembershipSetting::current()->private_rentals_enabled, 403);
        $this->assertDeskMethod($paymentMethod, $shift);

        return DB::transaction(function () use ($rental, $paymentMethod, $shift, $by): PrivateRentalPayment {
            // Two desks taking the same payment at once: only one gets past here.
            $rental = PrivateRental::whereKey($rental->id)->lockForUpdate()->firstOrFail();

            if (! $this->canCollectPayment($rental)) {
                throw new PrivateRentalRefused($this->isPaid($rental)
                    ? __('This booking is already paid.')
                    : __('Payment is taken on the day of the rental, before it ends.'));
            }

            $feeCents = Cents::of(PaymentMethod::feeFor($paymentMethod));

            return $rental->payments()->create([
                'amount' => Cents::toDecimal(Cents::of($rental->total) + $feeCents),
                'transaction_fee' => Cents::toDecimal($feeCents),
                'payment_method' => $paymentMethod,
                'register_shift_id' => $shift?->id,
                'recorded_by' => $by->id,
            ]);
        });
    }

    /**
     * Manager+ (refund-private-rental gate, checked by the caller): gives
     * back everything paid, as a negative row by $paymentMethod on $shift,
     * then cancels the booking. Cash back needs an open box. Refused once
     * anyone has checked in, like an unpaid cancel.
     *
     * @throws PrivateRentalRefused
     */
    public function refundAndCancel(PrivateRental $rental, string $paymentMethod, ?RegisterShift $shift, string $reason, User $by): PrivateRentalPayment
    {
        abort_unless(MembershipSetting::current()->private_rentals_enabled, 403);
        $this->assertDeskMethod($paymentMethod, $shift);

        if (blank($reason)) {
            throw new PrivateRentalRefused(__('Give a reason for the refund.'));
        }

        return DB::transaction(function () use ($rental, $paymentMethod, $shift, $reason, $by): PrivateRentalPayment {
            $rental = PrivateRental::whereKey($rental->id)->lockForUpdate()->firstOrFail();

            if (! $this->isChangeable($rental) || ! $this->isPaid($rental)) {
                throw new PrivateRentalRefused(__('Only a paid booking that hasn\'t ended can be refunded.'));
            }
            if ($rental->event->attendance()->exists()) {
                throw new PrivateRentalRefused(__('Someone is already checked in to this rental, so it can\'t be cancelled.'));
            }

            $refund = $rental->payments()->create([
                'amount' => Cents::toDecimal(-$this->netPaidCents($rental)),
                'transaction_fee' => '0.00',
                'payment_method' => $paymentMethod,
                'register_shift_id' => $shift?->id,
                'reason' => trim($reason),
                'recorded_by' => $by->id,
            ]);

            $rental->forceFill(['cancelled_at' => now(), 'cancelled_by' => $by->id])->save();
            $rental->event->archive($by);

            return $refund;
        });
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
        if ($this->isPaid($rental)) {
            throw new PrivateRentalRefused(__('This booking is paid, so it can\'t be changed.'));
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
        if ($this->isPaid($rental)) {
            throw new PrivateRentalRefused(__('This booking is paid: a Manager can cancel it with a refund.'));
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

    /**
     * The desk's own methods only, and cash only with an open box -- the
     * same rule as the desk's payment pickers, re-checked here so a forged
     * method or a cash payment with no box stops.
     */
    private function assertDeskMethod(string $paymentMethod, ?RegisterShift $shift): void
    {
        abort_unless(array_key_exists($paymentMethod, PaymentMethod::deskOptions()), 422);
        abort_if($shift === null && PaymentMethod::cashCodes()->contains($paymentMethod), 422, 'Cash needs an open box.');
        abort_if($shift !== null && $shift->closed_at !== null, 422, 'That box is closed.');
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
