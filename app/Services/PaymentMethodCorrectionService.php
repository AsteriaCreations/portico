<?php

namespace App\Services;

use App\Enums\Role;
use App\Filament\Admin\Resources\Members\MemberResource;
use App\Models\Attendance;
use App\Models\MembershipSetting;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodCorrection;
use App\Models\User;
use App\Support\Cents;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as LaravelNotification;

/**
 * "Correct payment method" at the Check-In Desk, Door+: tonight's visit was
 * recorded as paid one way (say Venmo) when it was really paid another
 * (cash). Only the visit's payment_method changes. Its amount_paid stays, as
 * that is what the patron was asked for and handed over, so the register
 * shift's live SUMs (RegisterShiftService) move the money from one method
 * to the other and nothing else changes. Each fix leaves a
 * payment_method_corrections row and notifies the Owners.
 *
 * A one-time method (Member::hasUsedOneTimeMethod()) is read from the visits
 * themselves, so moving a visit off one frees it again, and moving one onto
 * it spends it, unless another visit already has.
 *
 * Not here: a subscription or day pass bought in the same check-in is its own
 * record with its own method, and a visit on a closed shift stays as it was,
 * since a closed shift's numbers never move.
 */
class PaymentMethodCorrectionService
{
    /**
     * Why this visit's payment method can't be corrected, or null when it can.
     */
    public function refusalReason(Attendance $attendance, User $user): ?string
    {
        return match (true) {
            ! $user->role->atLeast(Role::Door) => __('Only Door staff or above can correct a payment method.'),
            ! $attendance->event->isCurrentlyActive() => __('Only a visit to tonight\'s event can be corrected.'),
            $attendance->checked_in_at === null => __('The member hasn\'t arrived yet.'),
            Cents::of($attendance->amount_paid) <= 0 => __('Nothing was paid for this visit.'),
            $attendance->prepaid_ahead => __('This visit was paid ahead, so its payment method can\'t be changed.'),
            in_array($attendance->payment_method, [PaymentMethod::VOUCHER, PaymentMethod::KIOSK], true) => __('This visit wasn\'t paid at the desk.'),
            (bool) $attendance->registerShift?->closed_at => __('This visit\'s register shift has closed, so its payment method can\'t be changed.'),
            $this->methodOptions($attendance) === [] => __('There is no other payment method to change it to.'),
            default => null,
        };
    }

    /**
     * The desk methods this visit could have been paid with instead: not its
     * current one, not Comp (which moves no money), not a one-time method the
     * member has already spent on another visit, and not a cash method when
     * the visit has no register shift for the cash to be in.
     *
     * @return array<string, string> code => label, for Select options.
     */
    public function methodOptions(Attendance $attendance): array
    {
        $cashCodes = PaymentMethod::cashCodes()->all();
        $oneTimeLocked = $this->oneTimeMethodLockedElsewhere($attendance);
        $oneTimeCodes = PaymentMethod::oneTimeCodes()->all();

        return collect(PaymentMethod::deskOptions())
            ->reject(fn (string $label, string $code): bool => $code === $attendance->payment_method
                || $code === 'comp'
                || ($oneTimeLocked && in_array($code, $oneTimeCodes, true))
                || ($attendance->register_shift_id === null && in_array($code, $cashCodes, true)))
            ->all();
    }

    /**
     * What the desk's modal says about the visit before staff pick.
     */
    public function summaryLabel(Attendance $attendance): string
    {
        return __('Recorded as :amount by :method. Pick how they really paid. The amount stays the same; a subscription or day pass bought tonight isn\'t changed. The Owner is notified.', [
            'amount' => MembershipSetting::formatMoney(Cents::toFloat(Cents::of($attendance->amount_paid))),
            'method' => $this->methodLabel($attendance->payment_method),
        ]);
    }

    public function correct(Attendance $attendance, User $by, string $newPaymentMethod, string $reason): PaymentMethodCorrection
    {
        abort_if(blank($reason), 422, 'A reason is required to correct a payment method.');

        $correction = DB::transaction(function () use ($attendance, $by, $newPaymentMethod, $reason): PaymentMethodCorrection {
            // Serializes two desks correcting the same visit at once.
            $locked = Attendance::whereKey($attendance->id)->lockForUpdate()->firstOrFail();

            $refusal = $this->refusalReason($locked, $by);
            abort_if($refusal !== null, 422, $refusal ?? '');
            abort_unless(array_key_exists($newPaymentMethod, $this->methodOptions($locked)), 422, __('That payment method can\'t be used for this visit.'));

            $oldPaymentMethod = $locked->payment_method;

            $locked->update(['payment_method' => $newPaymentMethod]);

            $member = $locked->member;
            if (in_array($newPaymentMethod, PaymentMethod::oneTimeCodes()->all(), true) && ! $member->isOneTimeMethodExempt()) {
                $member->recordOneTimeMethodUsage(PaymentMethod::where('code', $newPaymentMethod)->value('label'));
            }

            return PaymentMethodCorrection::create([
                'attendance_id' => $locked->id,
                'register_shift_id' => $locked->register_shift_id,
                'old_payment_method' => $oldPaymentMethod,
                'new_payment_method' => $newPaymentMethod,
                'amount' => $locked->amount_paid,
                'reason' => $reason,
                'corrected_by' => $by->id,
            ]);
        });

        $this->notifyOwners($correction);

        return $correction;
    }

    public function methodLabel(?string $code): string
    {
        if ($code === null) {
            return __('no payment method');
        }

        return PaymentMethod::where('code', $code)->value('label') ?? $code;
    }

    /**
     * Whether the member has spent a one-time method on some record other
     * than this visit -- this visit's own method is the one being replaced.
     */
    private function oneTimeMethodLockedElsewhere(Attendance $attendance): bool
    {
        $member = $attendance->member;

        if ($member->isOneTimeMethodExempt()) {
            return false;
        }

        $codes = PaymentMethod::oneTimeCodes();

        return $member->attendance()->whereKeyNot($attendance->id)->whereIn('payment_method', $codes)->exists()
            || $member->addOnDayPasses()->whereIn('payment_method', $codes)->exists();
    }

    /**
     * sendNow(), not ->sendToDatabase(): this app runs no queue worker (see
     * ShowrunnerCompRequests::notifyAdminsOfNewRequest()).
     */
    private function notifyOwners(PaymentMethodCorrection $correction): void
    {
        $owners = User::query()->where('active', true)->where('role', Role::Owner)->get();

        if ($owners->isEmpty()) {
            return;
        }

        $member = $correction->attendance->member;

        $notification = Notification::make()
            ->title(__('Payment method corrected: :username', ['username' => $member->username]))
            ->body(__(':amount on :event changed from :old to :new, by :name. Reason: :reason', [
                'amount' => MembershipSetting::formatMoney(Cents::toFloat(Cents::of($correction->amount))),
                'event' => $correction->attendance->event->label(),
                'old' => $this->methodLabel($correction->old_payment_method),
                'new' => $this->methodLabel($correction->new_payment_method),
                'name' => $correction->correctedBy->name,
                'reason' => $correction->reason,
            ]))
            ->actions([
                Action::make('view')
                    ->label('View member')
                    ->url(MemberResource::getUrl('edit', ['record' => $member]))
                    ->markAsRead(),
            ]);

        LaravelNotification::sendNow($owners, $notification->toDatabase());
    }
}
