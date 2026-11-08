<?php

namespace App\Services;

use App\Enums\Role;
use App\Filament\Admin\Resources\Members\MemberResource;
use App\Models\Attendance;
use App\Models\MembershipSetting;
use App\Models\PaymentCorrection;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Cents;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as LaravelNotification;

/**
 * A refund at the Check-In Desk, soon after someone checked in (within
 * membership_settings.desk_refund_window_minutes, default 30), for a patron
 * who leaves straight away. Door+, behind the desk_refunds_enabled flag.
 *
 * The visit stays on record: its amount_paid goes to 0 and it's marked
 * departed, and the refund is a payment_corrections row with no subscription
 * (net_amount is the negative refund). The visit's register shift is still
 * open, so its expected cash drops by the refund through the shift's
 * ordinary SUMs. The reason also becomes a behavior note on the visit (when
 * behavior notes are on), and the Owners are notified.
 *
 * Anything else -- a visit paid ahead, paid with voucher credit, already
 * corrected, or on a closed shift -- is for a Manager (Remove, on the
 * event's Attendance tab). A subscription or day pass bought in the same
 * check-in is its own record and isn't refunded here.
 */
class DeskRefundService
{
    public function windowMinutes(): int
    {
        return MembershipSetting::current()->desk_refund_window_minutes;
    }

    /**
     * Why this visit can't be refunded at the desk, or null when it can.
     */
    public function refusalReason(Attendance $attendance, User $user): ?string
    {
        return match (true) {
            ! MembershipSetting::current()->desk_refunds_enabled => __('Desk refunds are turned off.'),
            ! $user->role->atLeast(Role::Door) => __('Only Door staff or above can refund a visit.'),
            $attendance->checked_in_at === null => __('The member hasn\'t arrived yet.'),
            Cents::of($attendance->amount_paid) <= 0 => __('Nothing was paid for this visit.'),
            $attendance->paymentCorrections()->exists() => __('This visit has already been corrected or refunded.'),
            $attendance->prepaid_ahead => __('This visit was paid ahead, so a Manager has to refund it.'),
            Cents::of($attendance->voucher_coverage) > 0,
            $attendance->vouchers()->exists() => __('This visit used voucher credit, so a Manager has to refund it.'),
            (bool) $attendance->registerShift?->closed_at => __('This visit\'s register shift has closed, so a Manager has to refund it.'),
            $attendance->checked_in_at->copy()->addMinutes($this->windowMinutes())->isPast() => __('It\'s more than :minutes minutes since they checked in, so a Manager has to refund it.', ['minutes' => $this->windowMinutes()]),
            default => null,
        };
    }

    /**
     * What the desk's Refund modal tells staff to hand back.
     */
    public function refundLabel(Attendance $attendance): string
    {
        $method = PaymentMethod::where('code', $attendance->payment_method)->value('label') ?? $attendance->payment_method ?? __('no payment method');
        $register = $attendance->registerShift?->register?->name;

        $label = __('Refund :amount (:method)', [
            'amount' => MembershipSetting::formatMoney(Cents::toFloat(Cents::of($attendance->amount_paid))),
            'method' => $method,
        ]);

        return ($register ? $label.' '.__('from register :register.', ['register' => $register]) : $label.'.')
            .' '.__('The visit stays on their record, marked departed, with the reason as a behavior note. A subscription or day pass bought tonight isn\'t refunded. The Owner is notified.');
    }

    public function refund(Attendance $attendance, User $by, string $reason): PaymentCorrection
    {
        abort_if(blank($reason), 422, 'A reason is required to refund a visit.');

        $correction = DB::transaction(function () use ($attendance, $by, $reason): PaymentCorrection {
            // Serializes two desks refunding the same visit -- the second
            // sees the first's correction and is refused.
            $locked = Attendance::whereKey($attendance->id)->lockForUpdate()->firstOrFail();

            $refusal = $this->refusalReason($locked, $by);
            abort_if($refusal !== null, 422, $refusal ?? '');

            $refundCents = Cents::of($locked->amount_paid);

            $locked->update([
                'amount_paid' => Cents::toDecimal(0),
                'departed_at' => $locked->departed_at ?? now(),
            ]);

            if (MembershipSetting::current()->behavior_notes_enabled) {
                $locked->behaviorNotes()->create([
                    'note' => __('Refunded at the desk: :reason', ['reason' => $reason]),
                    'created_by' => $by->id,
                ]);
            }

            return PaymentCorrection::create([
                'attendance_id' => $locked->id,
                'subscription_id' => null,
                'register_shift_id' => $locked->register_shift_id,
                'payment_method' => $locked->payment_method,
                'old_amount_paid' => Cents::toDecimal($refundCents),
                'new_amount_paid' => Cents::toDecimal(0),
                'subscription_amount' => Cents::toDecimal(0),
                'net_amount' => Cents::toDecimal(-$refundCents),
                'reason' => $reason,
                'corrected_by' => $by->id,
            ]);
        });

        $this->notifyOwners($correction);

        return $correction;
    }

    /**
     * sendNow(), not ->sendToDatabase(): this app runs no queue worker (see
     * ShowrunnerCompRequests::notifyAdminsOfNewRequest()).
     */
    private function notifyOwners(PaymentCorrection $correction): void
    {
        $owners = User::query()->where('active', true)->where('role', Role::Owner)->get();

        if ($owners->isEmpty()) {
            return;
        }

        $member = $correction->attendance->member;

        $notification = Notification::make()
            ->title(__('Visit refunded at the desk: :username', ['username' => $member->username]))
            ->body(__(':amount on :event, by :name. Reason: :reason', [
                'amount' => MembershipSetting::formatMoney(Cents::toFloat(-Cents::of($correction->net_amount))),
                'event' => $correction->attendance->event->label(),
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
