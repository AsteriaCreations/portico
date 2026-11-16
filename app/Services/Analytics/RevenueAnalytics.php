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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
     * Everything taken in the period: the streams() added up.
     */
    public function totalCents(AnalyticsPeriod $period): int
    {
        return array_sum($this->streams($period));
    }

    /**
     * The same money as streams(), split by how it was paid instead of what
     * it was for, largest first. Refunds net off their method; a method that
     * nets to nothing is left out, and so is every $0 row. An amount with no
     * method on it (older or imported records) comes under a null code.
     *
     * @return Collection<int, array{code: ?string, cents: int, payments: int}>
     */
    public function byPaymentMethod(AnalyticsPeriod $period): Collection
    {
        $sources = [
            [$period->constrain(DB::table('attendance'), 'checked_in_at'), 'amount_paid'],
            [$period->constrainDate(DB::table('subscriptions'), 'paid_on'), 'amount_paid'],
            [$period->constrain(DB::table('private_rental_payments'), 'created_at'), 'amount'],
            [$period->constrain(DB::table('miscellaneous_payments'), 'created_at'), 'amount'],
            [$period->constrain(DB::table('add_on_day_passes'), 'created_at'), 'amount_paid'],
        ];

        $totals = [];

        foreach ($sources as [$query, $amount]) {
            $rows = $query->where($amount, '!=', 0)
                ->groupBy('payment_method')
                ->selectRaw("payment_method, SUM({$amount}) as total, COUNT(*) as payments")
                ->get();

            foreach ($rows as $row) {
                $key = (string) $row->payment_method;
                $totals[$key] ??= ['code' => $row->payment_method, 'cents' => 0, 'payments' => 0];
                $totals[$key]['cents'] += Cents::of($row->total);
                $totals[$key]['payments'] += (int) $row->payments;
            }
        }

        return collect($totals)
            ->filter(fn (array $method): bool => $method['cents'] !== 0)
            ->sortByDesc('cents')
            ->values();
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
