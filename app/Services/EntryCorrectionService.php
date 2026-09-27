<?php

namespace App\Services;

use App\Enums\EntryCoverageSource;
use App\Enums\Role;
use App\Filament\Admin\Resources\Members\MemberResource;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\MembershipSetting;
use App\Models\PaymentCorrection;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Cents;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as LaravelNotification;

/**
 * Same-night correction of a paid door entry into a subscription, for a
 * member who qualified but paid entry and then came back wanting to
 * subscribe. An exception to standard procedure, so it is only ever done
 * through here and always leaves a payment_corrections row.
 *
 * Everything posts to the visit's own register shift and payment method: the
 * new Subscription row, and the visit's amount_paid re-priced for entry
 * only. The drawer's existing live SUMs (RegisterShiftService) therefore
 * move by exactly the net difference -- collected when positive, refunded
 * when negative -- and nothing moves between shifts.
 */
class EntryCorrectionService
{
    /**
     * Why this visit can't be converted, or null when it can. The one place
     * the eligibility rules live -- the desk action's visibility and the
     * transaction itself both ask here.
     */
    public function refusalReason(Attendance $attendance): ?string
    {
        $event = $attendance->event;
        $member = $attendance->member;

        return match (true) {
            ! $event->isCurrentlyActive() => __('Only a visit to tonight\'s event can be converted.'),
            $attendance->checked_in_at === null => __('The member hasn\'t arrived yet.'),
            Cents::of($attendance->entry_fee) <= 0,
            $attendance->entry_covered_by !== EntryCoverageSource::None,
            Cents::of($attendance->voucher_coverage) > 0,
            $attendance->comp_reason_id !== null => __('Only a plain paid entry can be converted.'),
            ! $member->isSubscriptionEligible() => __('The member isn\'t subscription-eligible.'),
            $member->hasActiveSubscriptionFor(AddOn::entry(), $event->event_date->clone()->startOfMonth()) => __('The member already has a subscription for this month.'),
            Plan::currentFor(AddOn::entry(), now()) === null => __('No subscription plan is currently in effect.'),
            (bool) $attendance->registerShift?->closed_at => __('This visit\'s register shift is already closed.'),
            $attendance->paymentCorrections()->exists() => __('This visit has already been corrected.'),
            default => null,
        };
    }

    /**
     * What the conversion would come to, in cents, without writing anything.
     *
     * @return array{subscription: int, old_entry: int, new_entry: int, net: int}
     */
    public function quoteCents(Attendance $attendance): array
    {
        $subscription = Cents::of(Plan::currentFor(AddOn::entry(), now())?->price);
        $oldEntry = $this->entryDueCents($attendance);
        $newEntry = Cents::of($attendance->entry_fee) - $this->newEntryCoverageCents($attendance);

        return [
            'subscription' => $subscription,
            'old_entry' => $oldEntry,
            'new_entry' => $newEntry,
            'net' => $subscription + $newEntry - $oldEntry,
        ];
    }

    public function convertToSubscription(Attendance $attendance, User $by, string $reason): PaymentCorrection
    {
        $correction = DB::transaction(function () use ($attendance, $by, $reason): PaymentCorrection {
            // Serializes two Managers converting the same visit at once --
            // the second one sees the first's correction and is refused.
            $locked = Attendance::whereKey($attendance->id)->lockForUpdate()->firstOrFail();

            $refusal = $this->refusalReason($locked);
            abort_if($refusal !== null, 422, $refusal ?? '');

            $quote = $this->quoteCents($locked);
            $newCoverage = $this->newEntryCoverageCents($locked);
            $oldAmountPaid = Cents::of($locked->amount_paid);
            // Entry only: add-ons, pool lines and any transaction fee already
            // folded into amount_paid at check-in stay exactly as they were.
            $newAmountPaid = $oldAmountPaid - $quote['old_entry'] + $quote['new_entry'];

            $subscription = Subscription::create([
                'member_id' => $locked->member_id,
                'add_on_id' => AddOn::entry()->id,
                'covered_month' => $locked->event->event_date->clone()->startOfMonth()->toDateString(),
                'amount_paid' => Cents::toDecimal($quote['subscription']),
                'paid_on' => now(),
                'recorded_by' => $by->id,
                'payment_method' => $locked->payment_method,
                'register_shift_id' => $locked->register_shift_id,
                'notes' => __('Converted from door entry'),
            ]);

            $locked->update([
                'entry_coverage' => Cents::toDecimal($newCoverage),
                'entry_covered_by' => EntryCoverageSource::RegularSubscription,
                'amount_paid' => Cents::toDecimal($newAmountPaid),
            ]);

            return PaymentCorrection::create([
                'attendance_id' => $locked->id,
                'subscription_id' => $subscription->id,
                'register_shift_id' => $locked->register_shift_id,
                'payment_method' => $locked->payment_method,
                'old_amount_paid' => Cents::toDecimal($oldAmountPaid),
                'new_amount_paid' => Cents::toDecimal($newAmountPaid),
                'subscription_amount' => Cents::toDecimal($quote['subscription']),
                'net_amount' => Cents::toDecimal($quote['net']),
                'reason' => $reason,
                'corrected_by' => $by->id,
            ]);
        });

        $this->notifyOwners($correction);

        return $correction;
    }

    /**
     * "Collect $35" / "Refund $5" / "Nothing to collect" for a net amount.
     */
    public static function settlementLabel(int $netCents): string
    {
        return match (true) {
            $netCents > 0 => __('Collect :amount', ['amount' => MembershipSetting::formatMoney(Cents::toFloat($netCents))]),
            $netCents < 0 => __('Refund :amount', ['amount' => MembershipSetting::formatMoney(Cents::toFloat(-$netCents))]),
            default => __('Nothing to collect or refund'),
        };
    }

    private function entryDueCents(Attendance $attendance): int
    {
        return Cents::of($attendance->entry_fee)
            - Cents::of($attendance->entry_coverage)
            - Cents::of($attendance->voucher_coverage);
    }

    /**
     * The Regular subscription's credit against this visit, capped at the
     * entry fee actually charged -- the same coverage PricingService gives a
     * subscriber, against the fee snapshot on the row rather than whatever
     * the event's fee is now.
     */
    private function newEntryCoverageCents(Attendance $attendance): int
    {
        $breakdown = app(PricingService::class)->previewWithSelections($attendance->member, $attendance->event, regularSelected: true);

        return min(Cents::of($attendance->entry_fee), $breakdown->entryCoverageCents);
    }

    /**
     * An exception to standard procedure, so every active Owner hears about
     * it. sendNow(), not ->sendToDatabase(): this app runs no queue worker
     * (see ShowrunnerCompRequests::notifyAdminsOfNewRequest()).
     */
    private function notifyOwners(PaymentCorrection $correction): void
    {
        $owners = User::query()->where('active', true)->where('role', Role::Owner)->get();

        if ($owners->isEmpty()) {
            return;
        }

        $member = $correction->attendance->member;

        $notification = Notification::make()
            ->title(__('Entry converted to subscription: :username', ['username' => $member->username]))
            ->body(__(':settlement by :name. Reason: :reason', [
                'settlement' => self::settlementLabel(Cents::of($correction->net_amount)),
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
