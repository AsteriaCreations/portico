<?php

namespace App\Services;

use App\Models\AddOnDayPass;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\InstructorPayout;
use App\Models\MiscellaneousPayment;
use App\Models\PaymentMethod;
use App\Models\Register;
use App\Models\RegisterDrop;
use App\Models\RegisterShift;
use App\Models\Subscription;
use App\Models\User;
use App\Models\VisitRemoval;
use App\Support\Cents;
use Illuminate\Support\Facades\DB;

/**
 * Cash drawer accountability for a register — opening/closing a shift and
 * reconciling what the box should hold. Expected cash is always derived from
 * the shift's own opening count plus every cash-flagged (PaymentMethod::
 * cashCodes()) row attributed to it (attendance, subscriptions,
 * miscellaneous_payments, and add_on_day_passes -- the latter two for cash
 * taken outside the normal event/subscription flow, e.g. a vendor payment,
 * donation, or a one-time add-on day pass) minus the append-only
 * register_drops and instructor_payouts ledgers, the same "derived, never
 * stored" shape as CapacityService::occupancy(). Cash for a visit paid ahead
 * of its event's night (attendance.prepaid_ahead) is received here but comes
 * back out at close -- it's held for that event, not kept in the box -- see
 * heldPrepayCashCents(). One open shift per register
 * at a time is enforced here, not by a DB constraint — two concurrent
 * "open"/"close"/"drop" submissions for the same register or shift are
 * serialized with lockForUpdate() rather than trusting a check-then-act read,
 * the same mutex-row pattern used for the voucher balance in
 * CheckIn::checkInAction().
 */
class RegisterShiftService
{
    public function currentOpenShift(Register $register): ?RegisterShift
    {
        return RegisterShift::where('register_id', $register->id)->whereNull('closed_at')->first();
    }

    public function openShift(Register $register, User $user, float $openingCount, ?string $notes = null): RegisterShift
    {
        return DB::transaction(function () use ($register, $user, $openingCount, $notes) {
            // There's no shift row to lock yet when none is open — lock the
            // register itself as the mutex, so two concurrent "open shift"
            // submissions for the same register serialize instead of both
            // passing the check and creating two live shifts.
            Register::where('id', $register->id)->lockForUpdate()->first();

            abort_if($this->currentOpenShift($register), 409, 'This register already has an open shift.');

            return RegisterShift::create([
                'register_id' => $register->id,
                'opened_by' => $user->id,
                'opening_count' => Cents::toDecimal(Cents::of($openingCount)),
                'notes' => $notes,
            ]);
        });
    }

    public function recordDrop(RegisterShift $shift, User $user, float $amount, ?string $reason = null): RegisterDrop
    {
        return DB::transaction(function () use ($shift, $user, $amount, $reason) {
            $locked = $this->lockShift($shift);

            return RegisterDrop::create([
                'register_shift_id' => $locked->id,
                'amount' => Cents::toDecimal(Cents::of($amount)),
                'reason' => $reason,
                'recorded_by' => $user->id,
            ]);
        });
    }

    public function recordMiscPayment(RegisterShift $shift, User $user, float $amount, string $paymentMethod, string $notation): MiscellaneousPayment
    {
        return DB::transaction(function () use ($shift, $user, $amount, $paymentMethod, $notation) {
            $locked = $this->lockShift($shift);

            return MiscellaneousPayment::create([
                'register_shift_id' => $locked->id,
                'payment_method' => $paymentMethod,
                'amount' => Cents::toDecimal(Cents::of($amount)),
                'notation' => $notation,
                'recorded_by' => $user->id,
            ]);
        });
    }

    /**
     * Cash handed to an event's instructor out of this box. The desk allows
     * one payment while one stands (net paid isn't zero -- a fully reversed
     * payout can be paid again); $correction (Manager+, enforced by the
     * caller) adds a further signed row instead, e.g. money the instructor
     * handed back. The event row is locked too, so two terminals paying the
     * same instructor at once can't both get through the check.
     */
    public function recordInstructorPayout(RegisterShift $shift, Event $event, User $user, float $amount, ?string $notes = null, bool $correction = false): InstructorPayout
    {
        return DB::transaction(function () use ($shift, $event, $user, $amount, $notes, $correction) {
            $locked = $this->lockShift($shift);
            Event::where('id', $event->id)->lockForUpdate()->first();

            abort_if(! $correction && app(InstructorPayoutService::class)->netPaidCents($event) !== 0, 409, 'This instructor has already been paid.');

            return InstructorPayout::create([
                'event_id' => $event->id,
                'register_shift_id' => $locked->id,
                'amount' => Cents::toDecimal(Cents::of($amount)),
                'calculated_amount' => Cents::toDecimal(app(InstructorPayoutService::class)->calculate($event)->totalCents),
                'notes' => $notes,
                'recorded_by' => $user->id,
            ]);
        });
    }

    public function totalInstructorPayoutsCents(RegisterShift $shift): int
    {
        return Cents::of(InstructorPayout::where('register_shift_id', $shift->id)->sum('amount'));
    }

    public function cashReceived(RegisterShift $shift): float
    {
        return Cents::toFloat($this->cashReceivedCents($shift));
    }

    /**
     * Every sum here is taken in cents -- see App\Support\Cents. A drawer
     * that balances to the cent must come out at exactly zero variance,
     * which float sums of these four tables didn't reliably do.
     */
    public function cashReceivedCents(RegisterShift $shift): int
    {
        $cashCodes = PaymentMethod::cashCodes();

        $attendanceCash = Attendance::where('register_shift_id', $shift->id)
            ->whereIn('payment_method', $cashCodes)
            ->sum('amount_paid');

        $subscriptionCash = Subscription::where('register_shift_id', $shift->id)
            ->whereIn('payment_method', $cashCodes)
            ->sum('amount_paid');

        $miscCash = MiscellaneousPayment::where('register_shift_id', $shift->id)
            ->whereIn('payment_method', $cashCodes)
            ->sum('amount');

        $addOnDayPassCash = AddOnDayPass::where('register_shift_id', $shift->id)
            ->whereIn('payment_method', $cashCodes)
            ->sum('amount_paid');

        return Cents::of($attendanceCash) + Cents::of($subscriptionCash) + Cents::of($miscCash) + Cents::of($addOnDayPassCash)
            + $this->removedAfterCloseCents($shift, $cashCodes->all());
    }

    /**
     * Paid visits an Owner removed after this shift had already closed. Their
     * attendance rows are gone, so the SUMs above no longer see them; adding
     * them back keeps a closed shift's expected cash and variance exactly as
     * they were at close. A removal while the shift is open isn't added back:
     * that money really was handed back from this drawer. See
     * VisitRemovalService.
     *
     * @param  string[]|null  $paymentMethods  null for every method
     * @param  bool|null  $prepaidAhead  null for both prepays and same-night visits
     */
    private function removedAfterCloseCents(RegisterShift $shift, ?array $paymentMethods = null, ?bool $prepaidAhead = null): int
    {
        return Cents::of(VisitRemoval::where('register_shift_id', $shift->id)
            ->where('after_shift_closed', true)
            ->when($paymentMethods !== null, fn ($query) => $query->whereIn('payment_method', $paymentMethods))
            ->when($prepaidAhead !== null, fn ($query) => $query->where('prepaid_ahead', $prepaidAhead))
            ->sum('amount_paid'));
    }

    /**
     * Cash taken on this shift for visits paid ahead of their event's night.
     * It's counted in cashReceivedCents() -- it did come in here -- and taken
     * straight back off the expected close: at close it goes into that
     * event's prepay envelope, not back into the box, so the box balances
     * without it. Includes prepays an Owner removed after close, for the
     * same reason removedAfterCloseCents() does.
     */
    public function heldPrepayCashCents(RegisterShift $shift): int
    {
        $cashCodes = PaymentMethod::cashCodes()->all();

        return Cents::of(Attendance::where('register_shift_id', $shift->id)
            ->where('prepaid_ahead', true)
            ->whereIn('payment_method', $cashCodes)
            ->sum('amount_paid'))
            + $this->removedAfterCloseCents($shift, $cashCodes, prepaidAhead: true);
    }

    /**
     * heldPrepayCashCents(), per event -- one envelope each.
     *
     * @return array<int, int> event id => cents, earliest event first
     */
    public function heldPrepayCashByEventCents(RegisterShift $shift): array
    {
        $cashCodes = PaymentMethod::cashCodes()->all();
        $totals = [];

        $rows = Attendance::where('register_shift_id', $shift->id)
            ->where('prepaid_ahead', true)
            ->whereIn('payment_method', $cashCodes)
            ->get(['event_id', 'amount_paid'])
            ->concat(VisitRemoval::where('register_shift_id', $shift->id)
                ->where('after_shift_closed', true)
                ->where('prepaid_ahead', true)
                ->whereIn('payment_method', $cashCodes)
                ->get(['event_id', 'amount_paid']));

        foreach ($rows as $row) {
            $totals[$row->event_id] = ($totals[$row->event_id] ?? 0) + Cents::of($row->amount_paid);
        }

        $order = Event::whereIn('id', array_keys($totals))->orderBy('event_date')->orderBy('id')->pluck('id');

        return $order->mapWithKeys(fn (int $id) => [$id => $totals[$id]])
            ->filter(fn (int $cents) => $cents !== 0)
            ->all();
    }

    /**
     * Revenue split by category for this shift, across every payment method
     * (not just cash — this is a desk-facing "what came in tonight" picture,
     * not the box-reconciliation figure cashReceived() computes). Prepays for
     * a later event are their own bucket, kept out of "event".
     *
     * @return array{event: float, prepay: float, subscription: float, other: float}
     */
    public function revenueBreakdown(RegisterShift $shift): array
    {
        $other = Cents::of(MiscellaneousPayment::where('register_shift_id', $shift->id)->sum('amount'))
            + Cents::of(AddOnDayPass::where('register_shift_id', $shift->id)->sum('amount_paid'));

        return [
            'event' => Cents::toFloat($this->attendanceCents($shift, prepaidAhead: false)),
            'prepay' => Cents::toFloat($this->attendanceCents($shift, prepaidAhead: true)),
            'subscription' => Cents::toFloat(Cents::of(Subscription::where('register_shift_id', $shift->id)->sum('amount_paid'))),
            'other' => Cents::toFloat($other),
        ];
    }

    /**
     * Attendance on this shift plus paid visits removed after it closed, for
     * either prepays or same-night visits.
     *
     * @param  string[]|null  $paymentMethods  null for every method
     */
    private function attendanceCents(RegisterShift $shift, bool $prepaidAhead, ?array $paymentMethods = null): int
    {
        return Cents::of(Attendance::where('register_shift_id', $shift->id)
            ->where('prepaid_ahead', $prepaidAhead)
            ->when($paymentMethods !== null, fn ($query) => $query->whereIn('payment_method', $paymentMethods))
            ->sum('amount_paid'))
            + $this->removedAfterCloseCents($shift, $paymentMethods, $prepaidAhead);
    }

    /**
     * The same category split as revenueBreakdown(), scoped to cash-flagged
     * payment methods only -- what a desk person is actually holding as
     * physical cash at close, broken down the same way. Backs the
     * close-box envelope reminder (CheckIn::closeShiftAction(), behind
     * cash_envelope_reminder_enabled): the house procedure is to seal any cash collected in an envelope labelled with
     * this breakdown plus the event/date. In integer cents, so it always
     * sums to exactly cashReceivedCents($shift) -- same underlying rows,
     * just split by category.
     *
     * "prepay" is heldPrepayCashCents($shift), which goes in its own
     * envelope per event rather than this one.
     *
     * @return array{event: int, prepay: int, subscription: int, other: int}
     */
    public function cashRevenueBreakdownCents(RegisterShift $shift): array
    {
        $cashCodes = PaymentMethod::cashCodes();

        $other = Cents::of(MiscellaneousPayment::where('register_shift_id', $shift->id)->whereIn('payment_method', $cashCodes)->sum('amount'))
            + Cents::of(AddOnDayPass::where('register_shift_id', $shift->id)->whereIn('payment_method', $cashCodes)->sum('amount_paid'));

        return [
            // Plus paid visits an Owner removed after close, same as
            // cashReceivedCents() -- keeps the envelope breakdown summing to it.
            'event' => $this->attendanceCents($shift, prepaidAhead: false, paymentMethods: $cashCodes->all()),
            'prepay' => $this->heldPrepayCashCents($shift),
            'subscription' => Cents::of(Subscription::where('register_shift_id', $shift->id)->whereIn('payment_method', $cashCodes)->sum('amount_paid')),
            'other' => $other,
        ];
    }

    /**
     * Money taken on this shift by every non-cash method (Venmo, PayPal,
     * card, ...), per method -- the part of revenueBreakdown() that never
     * goes in the box, so the desk can tell it apart from the cash it
     * counts. Same rows as revenueBreakdown(), prepays included; methods
     * that took nothing (Voucher, Comp, a $0 kiosk check-in) are left out.
     *
     * @return array<string, int> method label => cents, in sort order
     */
    public function electronicByMethodCents(RegisterShift $shift): array
    {
        $cashCodes = PaymentMethod::cashCodes()->all();
        $totals = [];

        $rows = collect()
            ->concat(Attendance::where('register_shift_id', $shift->id)->get(['payment_method', 'amount_paid']))
            ->concat(VisitRemoval::where('register_shift_id', $shift->id)->where('after_shift_closed', true)->get(['payment_method', 'amount_paid']))
            ->concat(Subscription::where('register_shift_id', $shift->id)->get(['payment_method', 'amount_paid']))
            ->concat(AddOnDayPass::where('register_shift_id', $shift->id)->get(['payment_method', 'amount_paid']))
            ->concat(MiscellaneousPayment::where('register_shift_id', $shift->id)->get(['payment_method', 'amount'])
                ->map(fn (MiscellaneousPayment $payment) => (object) ['payment_method' => $payment->payment_method, 'amount_paid' => $payment->amount]));

        foreach ($rows as $row) {
            if ($row->payment_method === null || in_array($row->payment_method, $cashCodes, true)) {
                continue;
            }

            $totals[$row->payment_method] = ($totals[$row->payment_method] ?? 0) + Cents::of($row->amount_paid);
        }

        return PaymentMethod::whereIn('code', array_keys($totals))
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get(['code', 'label'])
            ->mapWithKeys(fn (PaymentMethod $method) => [$method->label => $totals[$method->code]])
            ->filter(fn (int $cents) => $cents !== 0)
            ->all();
    }

    public function totalDrops(RegisterShift $shift): float
    {
        return Cents::toFloat($this->totalDropsCents($shift));
    }

    public function totalDropsCents(RegisterShift $shift): int
    {
        return Cents::of(RegisterDrop::where('register_shift_id', $shift->id)->sum('amount'));
    }

    public function expectedClosingCount(RegisterShift $shift): float
    {
        return Cents::toFloat($this->expectedClosingCountCents($shift));
    }

    public function expectedClosingCountCents(RegisterShift $shift): int
    {
        return Cents::of($shift->opening_count) + $this->cashReceivedCents($shift) - $this->totalDropsCents($shift)
            - $this->totalInstructorPayoutsCents($shift) - $this->heldPrepayCashCents($shift);
    }

    public function closeShift(RegisterShift $shift, User $user, float $closingCount, ?string $notes = null): RegisterShift
    {
        return DB::transaction(function () use ($shift, $user, $closingCount, $notes) {
            $locked = $this->lockShift($shift);

            $locked->update([
                'closed_by' => $user->id,
                'closed_at' => now(),
                'closing_count' => Cents::toDecimal(Cents::of($closingCount)),
                'notes' => $notes ?? $locked->notes,
            ]);

            return $locked->fresh();
        });
    }

    public function variance(RegisterShift $shift): ?float
    {
        $cents = $this->varianceCents($shift);

        return $cents === null ? null : Cents::toFloat($cents);
    }

    /**
     * Counted minus expected, in cents: 0 when the box balances, positive
     * when it's over, negative when short. Compare this, not variance(),
     * when deciding which of those it is.
     */
    public function varianceCents(RegisterShift $shift): ?int
    {
        if (is_null($shift->closing_count)) {
            return null;
        }

        return Cents::of($shift->closing_count) - $this->expectedClosingCountCents($shift);
    }

    /**
     * Re-fetches and locks the shift row for the rest of the current
     * transaction — never trust closed_at on the instance the caller passed
     * in, since a concurrent close could have committed after it was loaded.
     */
    private function lockShift(RegisterShift $shift): RegisterShift
    {
        $locked = RegisterShift::where('id', $shift->id)->lockForUpdate()->first();

        abort_if(! $locked || $locked->closed_at, 409, 'This shift is already closed.');

        return $locked;
    }
}
