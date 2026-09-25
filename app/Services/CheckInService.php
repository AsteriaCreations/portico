<?php

namespace App\Services;

use App\Exceptions\CheckInRefused;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\AttendanceAddOn;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\RegisterShift;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Cents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Records one door check-in: any subscriptions bought with it, the priced
 * attendance row, its add-ons, and any voucher draw, all in one transaction
 * serialized by CapacityService::lockForAdmission().
 *
 * The caller has already decided the member may be admitted --
 * AdmissionPolicy, the prepay flag for a not-yet-active event, and any
 * existing attendance row are all checked before this, where a refusal is
 * an HTTP concern. What only the transaction can know is refused here
 * instead: CheckInRefused when the building or a capped add-on filled up
 * after the desk's screen loaded, or a QueryException (SQLSTATE 23000) when
 * another register wrote a conflicting row at the same moment. Either way
 * nothing is written.
 */
class CheckInService
{
    public function record(Member $member, Event $event, User $staff, CheckInRequest $request, ?RegisterShift $openShift): CheckInResult
    {
        // Ensures the settings row exists before lockForAdmission() needs it.
        MembershipSetting::current();
        $capacity = app(CapacityService::class);
        $paymentMethod = $request->paymentMethod;

        // Wrapped in a transaction so a race that slips past the caller's
        // existing-attendance check — two submissions landing close enough
        // that both saw no existing row — fails atomically on the database's
        // own unique constraint rather than leaving a half-written
        // subscription purchase with no attendance row behind.
        return DB::transaction(function () use ($member, $event, $staff, $request, $openShift, $paymentMethod, $capacity): CheckInResult {
            // First statement in the transaction, before any
            // other read -- see lockForAdmission(). Every
            // capped thing sold here (building spots, add-ons
            // with max_per_night) is re-checked under it, since
            // another register may have taken the last one
            // after this page was loaded.
            $capacity->lockForAdmission();
            if (! $capacity->hasRoom($event->event_date)) {
                throw new CheckInRefused(
                    __('Building at capacity — check-in not saved.'),
                    __('The building filled up after this screen loaded. Nothing was recorded or charged.'),
                );
            }

            // Read fresh rather than trusting $event: an Admin may have
            // archived it after this screen loaded.
            if (Event::whereKey($event->id)->whereNotNull('archived_at')->exists()) {
                throw new CheckInRefused(
                    __('Event archived — check-in not saved.'),
                    __('This event was archived after this screen loaded. Nothing was recorded or charged.'),
                );
            }

            $month = $event->event_date->clone()->startOfMonth();
            // All money in cents -- see App\Support\Cents.
            $subscriptionTotal = 0;

            // Filtered to currently-purchasable add-ons (Pool
            // excluded while pool_enabled is off) -- looping
            // only over what remains is itself the defense-in-
            // depth against a forged subscription_addon_{id}_
            // duration field for a now-disabled add-on, same as
            // the day-pass/subscription actions on the Check-In
            // page re-checking the same fresh query. Pricing itself
            // (below, via PricingService::price()) is
            // unaffected by this filter -- an event that
            // already has a pool fee still charges for it,
            // and an existing subscription still covers it,
            // regardless of whether new ones can be bought.
            $targets = collect([AddOn::entry()])
                ->merge(AddOn::subscribable()->get()
                    ->filter(fn (AddOn $addOn) => $addOn->isCurrentlyPurchasable()));

            foreach ($targets as $addOn) {
                if (! isset($request->subscriptionMonths[$addOn->id]) || ! $member->isSubscriptionEligible()) {
                    continue;
                }

                $months = $request->subscriptionMonths[$addOn->id];

                if ($months <= 1) {
                    // Unchanged from before bundles existed: a single-month
                    // purchase never shifts to a different month — if
                    // this exact month is already covered, it's just skipped.
                    // (Deliberately NOT routed through
                    // SubscriptionBundleService::purchase() even though it
                    // now handles months=1 correctly price-wise: purchase()
                    // always calls resolveStart(), which SHIFTS forward to
                    // the next free month on a conflict rather than skipping
                    // -- fine for an explicit multi-month bundle purchase,
                    // wrong here, where a stale/forged '1' selection for an
                    // already-covered month must silently no-op, not buy a
                    // different month than what was on screen.)
                    if ($member->hasActiveSubscriptionFor($addOn, $month)) {
                        continue;
                    }
                    // Priced as of today, not the event's date -- matches
                    // SubscriptionBundleService::purchase()'s own "always
                    // priced as of today" rule, since this is a payment
                    // happening now regardless of which (possibly future,
                    // door-prepay) event is selected.
                    $plan = Plan::currentFor($addOn, now());
                    if (! $plan) {
                        continue;
                    }
                    Subscription::create([
                        'member_id' => $member->id,
                        'add_on_id' => $addOn->id,
                        'covered_month' => $month->toDateString(),
                        'amount_paid' => $plan->price,
                        'paid_on' => now(),
                        'recorded_by' => $staff->id,
                        'payment_method' => $paymentMethod,
                        'register_shift_id' => $openShift?->id,
                    ]);
                    $subscriptionTotal += Cents::of($plan->price);

                    continue;
                }

                $bundleRows = app(SubscriptionBundleService::class)->purchase(
                    $member,
                    $addOn,
                    $months,
                    $month,
                    $staff,
                    $paymentMethod,
                    $openShift,
                );
                $subscriptionTotal += $bundleRows->sum(fn (Subscription $row) => Cents::of($row->amount_paid));
            }

            $breakdown = app(PricingService::class)->price($member, $event);

            // Re-fetched server-side, never trusted from the
            // submitted names/prices — same defense-in-depth as
            // everywhere else in this closure. A flat add-on
            // never goes through PricingService: it's a plain
            // addition to amount_paid, not comped or voucher-
            // covered. Also re-checked against add_ons_enabled
            // and this event's own add_on_event bindings -- a
            // forged selection from a session where the field
            // was hidden, or for an add-on this event doesn't
            // even offer, must be silently ignored, not honored.
            $selectedAddOns = MembershipSetting::current()->add_ons_enabled
                ? AddOn::offeredAt($event)->whereIn('id', $request->addOnIds)->get()
                : collect();
            $addOnTotal = $selectedAddOns->sum(fn (AddOn $addOn) => Cents::of($addOn->price));

            // The picker's disableOptionWhen() only knows the
            // sales committed when it last rendered. The whole
            // check-in is refused rather than quietly dropping
            // the add-on, so the desk never charges for less
            // than the member was told they're getting.
            $soldOut = $selectedAddOns->first(fn (AddOn $addOn): bool => ! $addOn->hasRoomOn($event->event_date));
            if ($soldOut) {
                throw new CheckInRefused(
                    __(':add_on sold out for tonight — check-in not saved.', ['add_on' => $soldOut->name]),
                    __('Another register sold the last one after this screen loaded. Nothing was recorded or charged. Remove it and check in again.'),
                );
            }

            $compReasonId = null;
            if ($request->compEntry && Gate::forUser($staff)->allows('grant-event-comp')) {
                $breakdown = app(PricingService::class)->applyEventComp($breakdown);
                $compReasonId = $request->compReasonId;
            }

            $voucherApplied = 0;
            $voucherPayer = null;
            if ($request->applyVoucher && MembershipSetting::current()->vouchers_enabled) {
                $voucherPayerId = ! empty($request->voucherPayerId) ? $request->voucherPayerId : $member->id;

                // Locked for the rest of this transaction: two check-ins
                // drawing from the same payer's balance at once must not
                // both read the pre-spend balance before either commits,
                // or the ledger can go negative with no error raised
                // (voucherBalance() is a live SUM with no DB constraint
                // against it going negative).
                $voucherPayer = Member::where('id', $voucherPayerId)->lockForUpdate()->first();

                if ($voucherPayer) {
                    $breakdown = app(PricingService::class)->applyVoucher(
                        $breakdown,
                        Cents::of($voucherPayer->voucherBalance()),
                        $request->voucherAmountCents,
                    );
                    $voucherApplied = $breakdown->voucherCoverageCents;
                }
            }

            // Folded onto the attendance row rather than tracked
            // separately, same as add-ons above -- one flat fee
            // for the whole transaction (entry plus whatever
            // subscription was bundled in via the same payment_
            // method), zero when nothing is actually changing
            // hands (e.g. a fully comped/vouchered entry with no
            // bundled subscription).
            $transactionFee = ($breakdown->amountPaidCents + $addOnTotal + $subscriptionTotal) > 0
                ? Cents::of(PaymentMethod::feeFor($paymentMethod))
                : 0;

            $attendance = Attendance::create([
                'member_id' => $member->id,
                'event_id' => $event->id,
                'checked_in_by' => $staff->id,
                // Re-derived from the event, not trusted from the
                // submission -- a forged checked_in_at for a
                // future prepay-only event is silently ignored
                // server-side, the same defense-in-depth as the
                // payment_method re-check on the Check-In page.
                'checked_in_at' => $event->isCurrentlyActive() ? ($request->checkedInAt ?? now()) : null,
                'payment_method' => $paymentMethod,
                'register_shift_id' => $openShift?->id,
                'on_behalf_note' => $request->onBehalfNote,
                'notes' => $request->notes,
                'comp_reason_id' => $compReasonId,
                ...$breakdown->toAttendanceAttributes(),
                // Overrides the breakdown's own amount_paid so
                // add-ons and the transaction fee land in the
                // same column the register reconciliation
                // already sums (RegisterShiftService::
                // cashReceived()/revenueBreakdown()) — no
                // changes needed there.
                'amount_paid' => Cents::toDecimal($breakdown->amountPaidCents + $addOnTotal + $transactionFee),
            ]);

            foreach ($selectedAddOns as $addOn) {
                AttendanceAddOn::create([
                    'attendance_id' => $attendance->id,
                    'add_on_id' => $addOn->id,
                    'name' => $addOn->name,
                    'price' => $addOn->price,
                    'is_overnight' => $addOn->is_overnight,
                ]);
            }

            // One row per subscribable add-on priced for this
            // event (Pool, at launch) -- coverage already
            // resolved by PricingService::build() above.
            foreach ($breakdown->addOnAttendanceRows() as $row) {
                AttendanceAddOn::create(['attendance_id' => $attendance->id, ...$row]);
            }

            if (in_array($paymentMethod, PaymentMethod::oneTimeCodes()->all(), true)) {
                $member->recordOneTimeMethodUsage(PaymentMethod::where('code', $paymentMethod)->value('label'));
            }

            if ($voucherApplied > 0 && $voucherPayer) {
                Voucher::create([
                    'member_id' => $voucherPayer->id,
                    'amount' => Cents::toDecimal(-$voucherApplied),
                    'reason' => $request->voucherReason,
                    'attendance_id' => $attendance->id,
                    'recorded_by' => $staff->id,
                ]);
            }

            return new CheckInResult($attendance, $breakdown, $subscriptionTotal, $voucherApplied, $addOnTotal);
        });
    }
}
