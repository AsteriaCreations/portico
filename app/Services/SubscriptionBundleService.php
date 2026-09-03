<?php

namespace App\Services;

use App\Enums\PlanType;
use App\Models\Member;
use App\Models\Plan;
use App\Models\RegisterShift;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Multi-month subscription plan purchases — a bulk-discounted plan (e.g. 3
 * months of Regular for $175) still has to materialize as one Subscription row
 * per calendar month, since that's what Member::hasActiveSubscription() and
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
     * an existing subscription for this member+planType.
     */
    public function resolveStart(Member $member, PlanType $planType, CarbonInterface $desiredStart, int $months): BundleStartResolution
    {
        $skipped = [];
        $candidate = $desiredStart->clone()->startOfMonth();

        for ($i = 0; $i <= self::MAX_LOOKAHEAD_MONTHS; $i++) {
            $conflict = collect(range(0, $months - 1))
                ->map(fn (int $offset) => $candidate->clone()->addMonthsNoOverflow($offset))
                ->first(fn (CarbonInterface $month) => $member->hasActiveSubscription($planType, $month));

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
     * @return Collection<int, Subscription>
     */
    public function purchase(
        Member $member,
        PlanType $planType,
        int $months,
        CarbonInterface $desiredStart,
        ?User $recordedBy,
        ?string $paymentMethod = null,
        ?RegisterShift $registerShift = null,
    ): Collection {
        abort_unless($member->isSubscriptionEligible(), 422, 'Member is not subscription-eligible.');

        $resolution = $this->resolveStart($member, $planType, $desiredStart, $months);

        // Priced as of today (the actual moment this purchase is being
        // transacted), not $resolution->start — that's always floored to a
        // covered month's 1st and can precede a plan's effective_from that
        // falls mid-month, which would otherwise make an option that
        // resolveStart() and the option label both show as available fail
        // to price at purchase time.
        $plan = Plan::currentFor($planType, now(), $months);
        abort_unless($plan, 422, "No {$months}-month {$planType->value} plan is currently effective.");

        $shares = $this->splitCents((int) round((float) $plan->price * 100), $months);
        $endMonth = $resolution->start->clone()->addMonthsNoOverflow($months - 1);
        $rangeLabel = $this->rangeLabel($resolution->start, $endMonth);

        return collect(range(0, $months - 1))->map(
            fn (int $index) => Subscription::create([
                'member_id' => $member->id,
                'plan_type' => $planType,
                'covered_month' => $resolution->start->clone()->addMonthsNoOverflow($index)->toDateString(),
                'amount_paid' => $shares[$index] / 100,
                'paid_on' => now(),
                'recorded_by' => $recordedBy?->id,
                'payment_method' => $paymentMethod,
                'register_shift_id' => $registerShift?->id,
                'notes' => ($index + 1)." of {$months} — ".ucfirst($planType->value)." subscription bundle covering {$rangeLabel}",
            ])
        );
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
            ? $start->format('F Y')
            : $start->format('F Y').' – '.$end->format('F Y');
    }
}
