<?php

namespace App\Services;

use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\Member;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\RegisterShift;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Cents;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Multi-month subscription plan purchases — a bulk-discounted plan (e.g. 3
 * months of Regular for $175) still has to materialize as one Subscription row
 * per calendar month, since that's what Member::hasActiveSubscriptionFor() and
 * PricingService key off. This is the one place that resolves which months
 * a bundle actually lands on — sliding the whole window past any
 * already-covered month rather than colliding with it — and splits the
 * bundle price across the created rows to the exact cent.
 */
class SubscriptionBundleService
{
    private const MAX_LOOKAHEAD_MONTHS = 36;

    /**
     * Slides a contiguous $months-length window forward one month at a time,
     * starting at $desiredStart, until every month in the window is free of
     * an existing subscription for this member+add-on.
     */
    public function resolveStart(Member $member, AddOn $addOn, CarbonInterface $desiredStart, int $months): BundleStartResolution
    {
        $skipped = [];
        $candidate = $desiredStart->clone()->startOfMonth();

        for ($i = 0; $i <= self::MAX_LOOKAHEAD_MONTHS; $i++) {
            $conflict = collect(range(0, $months - 1))
                ->map(fn (int $offset) => $candidate->clone()->addMonthsNoOverflow($offset))
                ->first(fn (CarbonInterface $month) => $member->hasActiveSubscriptionFor($addOn, $month));

            if (! $conflict) {
                $uniqueSkipped = collect($skipped)->unique(fn (CarbonInterface $month) => $month->toDateString())->values()->all();

                return new BundleStartResolution($candidate, $uniqueSkipped);
            }

            $skipped[] = $conflict->clone();
            $candidate = $candidate->clone()->addMonthNoOverflow();
        }

        abort(422, "No {$months}-month block free of existing coverage was found within ".self::MAX_LOOKAHEAD_MONTHS.' months.');
    }

    /**
     * What buying $months of $addOn at the check-in desk would charge right
     * now, in cents, without writing anything -- backs the desk's live Due
     * line. Mirrors CheckInService: priced as of today, and a single month
     * that's already covered is skipped (so costs nothing). Zero when no
     * plan is effective, since the purchase would be skipped too.
     */
    public function quoteCents(Member $member, AddOn $addOn, int $months, CarbonInterface $month): int
    {
        if ($months <= 1 && $member->hasActiveSubscriptionFor($addOn, $month)) {
            return 0;
        }

        return Cents::of(Plan::currentFor($addOn, now(), max($months, 1))?->price);
    }

    /**
     * @return Collection<int, Subscription>
     */
    public function purchase(
        Member $member,
        AddOn $addOn,
        int $months,
        CarbonInterface $desiredStart,
        ?User $recordedBy,
        ?string $paymentMethod = null,
        ?RegisterShift $registerShift = null,
    ): Collection {
        abort_unless($member->isSubscriptionEligible(), 422, 'Member is not subscription-eligible.');

        $resolution = $this->resolveStart($member, $addOn, $desiredStart, $months);

        // Priced as of today (the actual moment this purchase is being
        // transacted), not $resolution->start — that's always floored to a
        // covered month's 1st and can precede a plan's effective_from that
        // falls mid-month, which would otherwise make an option that
        // resolveStart() and the option label both show as available fail
        // to price at purchase time.
        $plan = Plan::currentFor($addOn, now(), $months);
        abort_unless($plan, 422, "No {$months}-month {$addOn->name} plan is currently effective.");

        $shares = $this->splitCents(Cents::of($plan->price), $months);
        $endMonth = $resolution->start->clone()->addMonthsNoOverflow($months - 1);
        $rangeLabel = $this->rangeLabel($resolution->start, $endMonth);

        return collect(range(0, $months - 1))->map(
            fn (int $index) => Subscription::create([
                'member_id' => $member->id,
                'add_on_id' => $addOn->id,
                'covered_month' => $resolution->start->clone()->addMonthsNoOverflow($index)->toDateString(),
                'amount_paid' => $shares[$index] / 100,
                'paid_on' => now(),
                'recorded_by' => $recordedBy?->id,
                'payment_method' => $paymentMethod,
                'register_shift_id' => $registerShift?->id,
                'notes' => ($index + 1)." of {$months} — {$addOn->name} subscription bundle covering {$rangeLabel}",
            ])
        );
    }

    /**
     * Spends up to $cents of $payer's voucher credit on subscription rows
     * just bought, month by month in order. Each covered row keeps its full
     * price split as amount_paid (money taken) + voucher_coverage (credit),
     * so RegisterShiftService's plain SUM(amount_paid) stays the money in
     * the box; a row the voucher covers entirely is recorded as the Voucher
     * method. One ledger row per covered month, linked to it (and to the
     * check-in it was bought with, if any). The caller caps $cents at the
     * payer's balance and holds the payer's row locked.
     *
     * @param  Collection<int, Subscription>  $rows
     * @return int cents actually applied
     */
    public function applyVoucher(Collection $rows, Member $payer, int $cents, string $reason, User $staff, ?Attendance $attendance = null): int
    {
        $remaining = max(0, $cents);

        foreach ($rows as $row) {
            $paidCents = Cents::of($row->amount_paid);
            $take = min($remaining, $paidCents);

            if ($take <= 0) {
                continue;
            }

            $row->update([
                'amount_paid' => Cents::toDecimal($paidCents - $take),
                'voucher_coverage' => Cents::toDecimal(Cents::of($row->voucher_coverage) + $take),
                'payment_method' => $paidCents === $take ? PaymentMethod::VOUCHER : $row->payment_method,
            ]);

            Voucher::create([
                'member_id' => $payer->id,
                'amount' => Cents::toDecimal(-$take),
                'reason' => $reason,
                'attendance_id' => $attendance?->id,
                'subscription_id' => $row->id,
                'recorded_by' => $staff->id,
            ]);

            $remaining -= $take;
        }

        return max(0, $cents) - $remaining;
    }

    /**
     * @return array<int, int> cents per row, summing exactly to $totalCents
     */
    private function splitCents(int $totalCents, int $months): array
    {
        $base = intdiv($totalCents, $months);
        $remainder = $totalCents % $months;

        return collect(range(0, $months - 1))
            ->map(fn (int $i) => $base + ($i < $remainder ? 1 : 0))
            ->all();
    }

    private function rangeLabel(CarbonInterface $start, CarbonInterface $end): string
    {
        return $start->isSameMonth($end)
            ? $start->translatedFormat('F Y')
            : $start->translatedFormat('F Y').' – '.$end->translatedFormat('F Y');
    }
}
