<?php

namespace App\Services\Analytics;

use App\Models\AddOnDayPass;
use App\Models\Attendance;
use App\Models\MembershipSetting;
use App\Models\MiscellaneousPayment;
use App\Models\PrivateRental;
use App\Models\PrivateRentalPayment;
use App\Models\RegisterShift;
use App\Models\Subscription;
use App\Models\Voucher;
use App\Services\RegisterShiftService;
use App\Support\AnalyticsPeriod;
use App\Support\Cents;

/**
 * Money taken, for the Analytics page, in integer cents (App\Support\Cents).
 * Everything is windowed on the day the money changed hands, not the period
 * it paid for.
 */
class RevenueAnalytics
{
    private ?bool $tracksRentals = null;

    public function __construct(private RegisterShiftService $registerShifts) {}

    /**
     * The same streams as RegisterShiftService::revenueBreakdown(), but for
     * the whole club over a period rather than one shift: Event (attendance,
     * which already has add-on revenue folded into amount_paid), Subscription,
     * Rental (a rental's payments plus what its attendees paid at the door),
     * and Other (miscellaneous payments and day passes). Rental is present
     * only when tracksRentals().
     *
     * @return array<string, int> cents, keyed event / subscription / rental / other
     */
    public function streams(AnalyticsPeriod $period): array
    {
        $rentalEventIds = PrivateRental::query()->select('event_id');

        $streams = [
            'event' => Cents::of($period->constrain(Attendance::query(), 'checked_in_at')
                ->whereNotIn('event_id', $rentalEventIds)
                ->sum('amount_paid')),
            'subscription' => $this->subscriptionCents($period),
        ];

        if ($this->tracksRentals()) {
            $streams['rental'] = Cents::of($period->constrain(PrivateRentalPayment::query(), 'created_at')->sum('amount'))
                + Cents::of($period->constrain(Attendance::query(), 'checked_in_at')
                    ->whereIn('event_id', $rentalEventIds)
                    ->sum('amount_paid'));
        }

        $streams['other'] = Cents::of($period->constrain(MiscellaneousPayment::query(), 'created_at')->sum('amount'))
            + Cents::of($period->constrain(AddOnDayPass::query(), 'created_at')->sum('amount_paid'));

        return $streams;
    }

    /**
     * Once the club uses private rentals, or has ever booked one, so a club
     * without them never sees an empty Rental stream.
     */
    public function tracksRentals(): bool
    {
        // Asked once per chart step, so kept for the life of this instance.
        return $this->tracksRentals ??= MembershipSetting::current()->private_rentals_enabled || PrivateRental::query()->exists();
    }

    /**
     * By paid_on (when the money changed hands), not covered_month.
     */
    public function subscriptionCents(AnalyticsPeriod $period): int
    {
        return Cents::of($period->constrainDate(Subscription::query(), 'paid_on')->sum('amount_paid'));
    }

    /**
     * Every voucher row, grants and redemptions alike: what the club still
     * owes as future free admission. A balance today, not a period figure.
     */
    public function voucherLiabilityCents(): int
    {
        return Cents::of(Voucher::sum('amount'));
    }

    /**
     * Over the register shifts closed in the period.
     *
     * @return array{total_cents: int, shifts: int, with_variance: int}
     */
    public function registerVariance(AnalyticsPeriod $period): array
    {
        $variances = $period->constrain(RegisterShift::query(), 'closed_at')
            ->get()
            ->map(fn (RegisterShift $shift): ?int => $this->registerShifts->varianceCents($shift));

        return [
            'total_cents' => (int) $variances->sum(),
            'shifts' => $variances->count(),
            'with_variance' => $variances->filter(fn (?int $variance): bool => $variance !== null && $variance !== 0)->count(),
        ];
    }
}
