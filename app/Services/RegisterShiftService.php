<?php

namespace App\Services;

use App\Models\AddOnDayPass;
use App\Models\Attendance;
use App\Models\MiscellaneousPayment;
use App\Models\PaymentMethod;
use App\Models\Register;
use App\Models\RegisterDrop;
use App\Models\RegisterShift;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cash drawer accountability for a register — opening/closing a shift and
 * reconciling what the box should hold. Expected cash is always derived from
 * the shift's own opening count plus every cash-flagged (PaymentMethod::
 * cashCodes()) row attributed to it (attendance, subscriptions,
 * miscellaneous_payments, and add_on_day_passes -- the latter two for cash
 * taken outside the normal event/subscription flow, e.g. a vendor payment,
 * donation, or a one-time add-on day pass) minus the append-only
 * register_drops ledger, the same "derived, never
 * stored" shape as CapacityService::occupancy(). One open shift per register
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
                'opening_count' => $openingCount,
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
                'amount' => $amount,
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
                'amount' => $amount,
                'notation' => $notation,
                'recorded_by' => $user->id,
            ]);
        });
    }

    public function cashReceived(RegisterShift $shift): float
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

        return (float) $attendanceCash + (float) $subscriptionCash + (float) $miscCash + (float) $addOnDayPassCash;
    }

    /**
     * Revenue split by category for this shift, across every payment method
     * (not just cash — this is a desk-facing "what came in tonight" picture,
     * not the box-reconciliation figure cashReceived() computes).
     *
     * @return array{event: float, subscription: float, other: float}
     */
    public function revenueBreakdown(RegisterShift $shift): array
    {
        $other = (float) MiscellaneousPayment::where('register_shift_id', $shift->id)->sum('amount')
            + (float) AddOnDayPass::where('register_shift_id', $shift->id)->sum('amount_paid');

        return [
            'event' => (float) Attendance::where('register_shift_id', $shift->id)->sum('amount_paid'),
            'subscription' => (float) Subscription::where('register_shift_id', $shift->id)->sum('amount_paid'),
            'other' => $other,
        ];
    }

    public function totalDrops(RegisterShift $shift): float
    {
        return (float) RegisterDrop::where('register_shift_id', $shift->id)->sum('amount');
    }

    public function expectedClosingCount(RegisterShift $shift): float
    {
        return (float) $shift->opening_count + $this->cashReceived($shift) - $this->totalDrops($shift);
    }

    public function closeShift(RegisterShift $shift, User $user, float $closingCount, ?string $notes = null): RegisterShift
    {
        return DB::transaction(function () use ($shift, $user, $closingCount, $notes) {
            $locked = $this->lockShift($shift);

            $locked->update([
                'closed_by' => $user->id,
                'closed_at' => now(),
                'closing_count' => $closingCount,
                'notes' => $notes ?? $locked->notes,
            ]);

            return $locked->fresh();
        });
    }

    public function variance(RegisterShift $shift): ?float
    {
        if (is_null($shift->closing_count)) {
            return null;
        }

        return (float) $shift->closing_count - $this->expectedClosingCount($shift);
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
