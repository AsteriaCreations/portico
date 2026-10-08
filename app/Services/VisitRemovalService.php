<?php

namespace App\Services;

use App\Enums\Role;
use App\Filament\Admin\Resources\VisitRemovals\VisitRemovalResource;
use App\Models\Attendance;
use App\Models\CompRequest;
use App\Models\MembershipSetting;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\VisitRemoval;
use App\Support\Cents;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as LaravelNotification;

/**
 * The one way a visit (an attendance row) is removed. A $0 visit -- a prepay
 * or Comp List entry taken off before the night, a duplicate -- goes quietly,
 * as it always did. A paid visit is money changing hands in reverse, so it
 * needs a reason, leaves a visit_removals snapshot, and notifies the Owners:
 * Manager+ while the visit's register shift is still open (the desk hands the
 * money back and that shift's expected cash drops to match), Owner only once
 * it has closed (RegisterShiftService then adds the removal back, so the
 * closed shift's numbers never move).
 *
 * A visit that other records point at -- voucher activity, behavior notes, a
 * comp request, a payment correction -- is never removed: those are
 * append-only histories of that visit.
 */
class VisitRemovalService
{
    public function refusalReason(Attendance $attendance, User $user): ?string
    {
        if ($dependent = $this->dependentLabel($attendance)) {
            return __('This visit has :records, so it can\'t be removed.', ['records' => $dependent]);
        }

        if (! $user->role->atLeast(Role::Manager)) {
            return __('Only a Manager or above can remove a visit.');
        }

        if ($this->isPaid($attendance) && $attendance->registerShift?->closed_at && ! $user->role->atLeast(Role::Owner)) {
            return __('Its register shift has closed, so only an Owner can remove this paid visit.');
        }

        return null;
    }

    public function isPaid(Attendance $attendance): bool
    {
        return Cents::of($attendance->amount_paid) > 0;
    }

    /**
     * Removes the visit, returning the log row for a paid one (null for $0).
     */
    public function remove(Attendance $attendance, User $by, ?string $reason = null): ?VisitRemoval
    {
        $removal = DB::transaction(function () use ($attendance, $by, $reason): ?VisitRemoval {
            $locked = Attendance::whereKey($attendance->id)->lockForUpdate()->firstOrFail();

            $refusal = $this->refusalReason($locked, $by);
            abort_if($refusal !== null, 422, $refusal ?? '');

            $removal = null;

            if ($this->isPaid($locked)) {
                abort_if(blank($reason), 422, 'A reason is required to remove a paid visit.');

                $removal = VisitRemoval::create([
                    'member_id' => $locked->member_id,
                    'event_id' => $locked->event_id,
                    'register_shift_id' => $locked->register_shift_id,
                    'payment_method' => $locked->payment_method,
                    'amount_paid' => $locked->amount_paid,
                    'checked_in_at' => $locked->checked_in_at,
                    'after_shift_closed' => (bool) $locked->registerShift?->closed_at,
                    'prepaid_ahead' => $locked->prepaid_ahead,
                    'reason' => $reason,
                    'removed_by' => $by->id,
                ]);
            }

            // attendance_add_ons cascade with it.
            $locked->delete();

            return $removal;
        });

        if ($removal) {
            $this->notifyOwners($removal);
        }

        return $removal;
    }

    /**
     * What the Remove modal tells staff to hand back, or null for a $0 visit.
     */
    public function refundLabel(Attendance $attendance): ?string
    {
        if (! $this->isPaid($attendance)) {
            return null;
        }

        $amount = MembershipSetting::formatMoney(Cents::toFloat(Cents::of($attendance->amount_paid)));
        $register = $attendance->registerShift?->register?->name;

        // Prepaid cash goes into the event's prepay envelope as soon as it's
        // taken (or never touched a box, from the Prepay List), and the
        // shift's expected cash already leaves it out -- so a refund comes
        // from the envelope, open shift or not.
        $heldOutsideBox = $attendance->prepaid_ahead
            && PaymentMethod::cashCodes()->contains($attendance->payment_method);

        if ($heldOutsideBox) {
            return __('Refund :amount from the prepay cash held for this event. No register drawer is changed.', ['amount' => $amount]);
        }

        if ($attendance->registerShift?->closed_at) {
            return __('Paid :amount. Its register shift has already closed, so the drawer isn\'t changed; any refund happens outside it.', ['amount' => $amount]);
        }

        return $register
            ? __('Refund :amount (:method) from register :register.', ['amount' => $amount, 'method' => $attendance->payment_method ?? '—', 'register' => $register])
            : __('Refund :amount (:method).', ['amount' => $amount, 'method' => $attendance->payment_method ?? '—']);
    }

    private function dependentLabel(Attendance $attendance): ?string
    {
        return match (true) {
            $attendance->vouchers()->exists() => __('voucher activity'),
            $attendance->behaviorNotes()->exists() => __('behavior notes'),
            CompRequest::where('attendance_id', $attendance->id)->exists() => __('a comp request'),
            $attendance->paymentCorrections()->exists() => __('a payment correction'),
            default => null,
        };
    }

    /**
     * sendNow(), not ->sendToDatabase(): this app runs no queue worker (see
     * ShowrunnerCompRequests::notifyAdminsOfNewRequest()).
     */
    private function notifyOwners(VisitRemoval $removal): void
    {
        $owners = User::query()->where('active', true)->where('role', Role::Owner)->get();

        if ($owners->isEmpty()) {
            return;
        }

        $notification = Notification::make()
            ->title(__('Paid visit removed: :username', ['username' => $removal->member->username]))
            ->body(__(':amount on :event, by :name. Reason: :reason', [
                'amount' => MembershipSetting::formatMoney(Cents::toFloat(Cents::of($removal->amount_paid))),
                'event' => $removal->event->label(),
                'name' => $removal->removedBy->name,
                'reason' => $removal->reason,
            ]))
            ->actions([
                Action::make('view')
                    ->label('View removals')
                    ->url(VisitRemovalResource::getUrl('index'))
                    ->markAsRead(),
            ]);

        LaravelNotification::sendNow($owners, $notification->toDatabase());
    }
}
