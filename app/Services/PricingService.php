<?php

namespace App\Services;

use App\Enums\EntryCoverageSource;
use App\Enums\PlanType;
use App\Enums\PoolCoverageSource;
use App\Models\Event;
use App\Models\Member;
use App\Models\Plan;

/**
 * The single source of truth for what a member owes at an event. Entry and pool
 * are priced independently — each subscription only ever discounts its own
 * component. See docs/BLUEPRINT.md "Fee pipeline" for the spec
 * this implements.
 */
class PricingService
{
    public function price(Member $member, Event $event): PriceBreakdown
    {
        $month = $event->event_date->clone()->startOfMonth();

        return $this->build(
            $member,
            $event,
            regularActive: $member->hasActiveSubscription(PlanType::Regular, $month),
            poolActive: $member->hasActiveSubscription(PlanType::Pool, $month),
            poolDayPassActive: $member->hasPoolDayPassFor($event),
        );
    }

    /**
     * Same coverage math as price(), but regular/pool subscription coverage is
     * driven by caller-supplied booleans instead of real subscription rows —
     * lets the check-in page show a live running total for an option staff
     * have selected but not yet purchased, without writing a speculative
     * Subscription row. A selection only ever adds coverage on top of
     * whatever's already active for real; it never removes existing coverage.
     */
    public function previewWithSelections(Member $member, Event $event, bool $regularSelected, bool $poolSelected): PriceBreakdown
    {
        $month = $event->event_date->clone()->startOfMonth();

        return $this->build(
            $member,
            $event,
            regularActive: $regularSelected || $member->hasActiveSubscription(PlanType::Regular, $month),
            poolActive: $poolSelected || $member->hasActiveSubscription(PlanType::Pool, $month),
            poolDayPassActive: $member->hasPoolDayPassFor($event),
        );
    }

    private function build(Member $member, Event $event, bool $regularActive, bool $poolActive, bool $poolDayPassActive): PriceBreakdown
    {
        $entryFee = (float) $event->entry_fee;
        $poolFee = (float) $event->pool_fee;

        if ($member->category->is_comped) {
            return new PriceBreakdown(
                entryFee: $entryFee,
                entryCoverage: $entryFee,
                entryCoveredBy: EntryCoverageSource::Comp,
                poolFee: $poolFee,
                poolCoverage: $poolFee,
                poolCoveredBy: PoolCoverageSource::Comp,
                voucherCoverage: 0.0,
                amountPaid: 0.0,
            );
        }

        if ($entryFee > 0 && $event->host_id !== null && (int) $event->host_id === $member->id) {
            $entryCoverage = $entryFee;
            $entryCoveredBy = EntryCoverageSource::Host;
        } elseif ($entryFee > 0 && $regularActive) {
            $credit = (float) (Plan::currentFor(PlanType::Regular, $event->event_date)?->credit ?? 0);
            $entryCoverage = min($entryFee, $credit);
            $entryCoveredBy = EntryCoverageSource::RegularSubscription;
        } else {
            $entryCoverage = 0.0;
            $entryCoveredBy = EntryCoverageSource::None;
        }

        if ($poolFee > 0 && $poolDayPassActive) {
            $poolCoverage = $poolFee;
            $poolCoveredBy = PoolCoverageSource::DayPass;
        } elseif ($poolFee > 0 && $poolActive) {
            $poolCoverage = $poolFee;
            $poolCoveredBy = PoolCoverageSource::PoolSubscription;
        } else {
            $poolCoverage = 0.0;
            $poolCoveredBy = PoolCoverageSource::None;
        }

        return new PriceBreakdown(
            entryFee: $entryFee,
            entryCoverage: $entryCoverage,
            entryCoveredBy: $entryCoveredBy,
            poolFee: $poolFee,
            poolCoverage: $poolCoverage,
            poolCoveredBy: $poolCoveredBy,
            voucherCoverage: 0.0,
            amountPaid: ($entryFee - $entryCoverage) + ($poolFee - $poolCoverage),
        );
    }

    /**
     * Applies voucher credit on top of an already-priced breakdown. A separate,
     * explicit step from price() because it's opt-in and partial at check-in,
     * not a deterministic function of member+event — see
     * docs/BLUEPRINT.md "Vouchers". Always capped at both the
     * payer's available balance and what's still due; never goes negative.
     */
    /**
     * A manager waiving this specific visit's entry fee (e.g. a volunteer
     * "House Sub" that night) — a per-visit override layered on top of
     * price(), the same shape as applyVoucher(), not baked into price()
     * itself: that's a property of member+event, this is a one-off
     * decision. Fully overrides whatever price() already computed for entry
     * (category comp, regular-subscription coverage, or nothing). Entry only — pool
     * stays priced independently. See docs/BLUEPRINT.md
     * "Still open" ("House sub comped rates").
     */
    public function applyEventComp(PriceBreakdown $breakdown): PriceBreakdown
    {
        return new PriceBreakdown(
            entryFee: $breakdown->entryFee,
            entryCoverage: $breakdown->entryFee,
            entryCoveredBy: EntryCoverageSource::EventComp,
            poolFee: $breakdown->poolFee,
            poolCoverage: $breakdown->poolCoverage,
            poolCoveredBy: $breakdown->poolCoveredBy,
            voucherCoverage: $breakdown->voucherCoverage,
            amountPaid: max(0.0, $breakdown->amountPaid - ($breakdown->entryFee - $breakdown->entryCoverage)),
        );
    }

    public function applyVoucher(PriceBreakdown $breakdown, float $availableBalance, float $requestedAmount): PriceBreakdown
    {
        $applied = max(0.0, min($requestedAmount, $availableBalance, $breakdown->amountPaid));

        return new PriceBreakdown(
            entryFee: $breakdown->entryFee,
            entryCoverage: $breakdown->entryCoverage,
            entryCoveredBy: $breakdown->entryCoveredBy,
            poolFee: $breakdown->poolFee,
            poolCoverage: $breakdown->poolCoverage,
            poolCoveredBy: $breakdown->poolCoveredBy,
            voucherCoverage: $applied,
            amountPaid: $breakdown->amountPaid - $applied,
        );
    }
}
