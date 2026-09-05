<?php

namespace App\Services;

use App\Enums\AddOnCoverageSource;
use App\Enums\EntryCoverageSource;
use App\Models\AddOn;
use App\Models\Event;
use App\Models\Member;
use App\Models\Plan;
use Illuminate\Support\Collection;

/**
 * The single source of truth for what a member owes at an event. Entry is
 * priced on its own (host bypass, category comp, the Regular subscription's
 * per-visit credit); every subscribable add-on (Pool, at launch — see
 * AddOn::subscribable()) is priced independently of entry and of every other
 * add-on, each drawing on its own subscription/day-pass/comp coverage. See
 * docs/BLUEPRINT.md "Fee pipeline" for the spec this implements.
 */
class PricingService
{
    public function price(Member $member, Event $event): PriceBreakdown
    {
        $month = $event->event_date->clone()->startOfMonth();
        $entry = AddOn::entry();
        // A subscribable add-on the member can't currently use (e.g. Pool
        // with a lapsed Pool Waiver — see PaperworkType::gates_add_on_id and
        // Member::canUseAddOn()) drops out entirely: no line, no charge.
        $subscribable = AddOn::subscribable()->get()
            ->filter(fn (AddOn $addOn) => $member->canUseAddOn($addOn))
            ->values();

        return $this->build(
            $member,
            $event,
            regularActive: $member->hasActiveSubscriptionFor($entry, $month),
            subscribableAddOns: $subscribable,
            activeAddOnSubscriptionIds: $subscribable->filter(fn (AddOn $addOn) => $member->hasActiveSubscriptionFor($addOn, $month))->pluck('id')->all(),
            addOnDayPassIds: $subscribable->filter(fn (AddOn $addOn) => $member->hasDayPassFor($addOn, $event))->pluck('id')->all(),
        );
    }

    /**
     * Same coverage math as price(), but regular/add-on subscription
     * coverage additionally counts whatever's currently *selected* on the
     * check-in page — lets the page show a live running total for an option
     * staff have picked but not yet purchased, without writing a
     * speculative Subscription row. A selection only ever adds coverage on
     * top of whatever's already active for real; it never removes existing
     * coverage.
     *
     * @param  int[]  $addOnSubscriptionIdsSelected  add-on IDs staff have picked a subscription duration for on the live pricing form
     */
    public function previewWithSelections(Member $member, Event $event, bool $regularSelected, array $addOnSubscriptionIdsSelected = []): PriceBreakdown
    {
        $month = $event->event_date->clone()->startOfMonth();
        $entry = AddOn::entry();
        // See price() — a gated add-on the member can't currently use is
        // filtered out here too, so the live preview matches the charge.
        $subscribable = AddOn::subscribable()->get()
            ->filter(fn (AddOn $addOn) => $member->canUseAddOn($addOn))
            ->values();

        return $this->build(
            $member,
            $event,
            regularActive: $regularSelected || $member->hasActiveSubscriptionFor($entry, $month),
            subscribableAddOns: $subscribable,
            activeAddOnSubscriptionIds: $subscribable->filter(fn (AddOn $addOn) => in_array($addOn->id, $addOnSubscriptionIdsSelected, true) || $member->hasActiveSubscriptionFor($addOn, $month))->pluck('id')->all(),
            addOnDayPassIds: $subscribable->filter(fn (AddOn $addOn) => $member->hasDayPassFor($addOn, $event))->pluck('id')->all(),
        );
    }

    /**
     * @param  Collection<int, AddOn>  $subscribableAddOns
     * @param  int[]  $activeAddOnSubscriptionIds
     * @param  int[]  $addOnDayPassIds
     */
    private function build(Member $member, Event $event, bool $regularActive, Collection $subscribableAddOns, array $activeAddOnSubscriptionIds, array $addOnDayPassIds): PriceBreakdown
    {
        $entryFee = (float) $event->entry_fee;

        if ($member->category->is_comped) {
            $addOnLines = $subscribableAddOns
                ->map(function (AddOn $addOn) use ($event) {
                    $fee = $addOn->priceFor($event);

                    return $fee !== null ? new AddOnPriceLine($addOn, $fee, $fee, AddOnCoverageSource::Comp) : null;
                })
                ->filter()
                ->values()
                ->all();

            return new PriceBreakdown(
                entryFee: $entryFee,
                entryCoverage: $entryFee,
                entryCoveredBy: EntryCoverageSource::Comp,
                addOnLines: $addOnLines,
                voucherCoverage: 0.0,
                amountPaid: 0.0,
            );
        }

        if ($entryFee > 0 && $event->host_id !== null && (int) $event->host_id === $member->id) {
            $entryCoverage = $entryFee;
            $entryCoveredBy = EntryCoverageSource::Host;
        } elseif ($entryFee > 0 && $regularActive) {
            $credit = (float) (Plan::currentFor(AddOn::entry(), $event->event_date)?->credit ?? 0);
            $entryCoverage = min($entryFee, $credit);
            $entryCoveredBy = EntryCoverageSource::RegularSubscription;
        } else {
            $entryCoverage = 0.0;
            $entryCoveredBy = EntryCoverageSource::None;
        }

        $addOnLines = [];
        foreach ($subscribableAddOns as $addOn) {
            $fee = $addOn->priceFor($event);
            if ($fee === null) {
                continue;
            }

            if (in_array($addOn->id, $addOnDayPassIds, true)) {
                $coverage = $fee;
                $coveredBy = AddOnCoverageSource::DayPass;
            } elseif (in_array($addOn->id, $activeAddOnSubscriptionIds, true)) {
                $credit = Plan::currentFor($addOn, $event->event_date)?->credit;
                $coverage = $credit !== null ? min($fee, (float) $credit) : $fee;
                $coveredBy = AddOnCoverageSource::Subscription;
            } else {
                $coverage = 0.0;
                $coveredBy = AddOnCoverageSource::None;
            }

            $addOnLines[] = new AddOnPriceLine($addOn, $fee, $coverage, $coveredBy);
        }

        $amountPaid = ($entryFee - $entryCoverage) + collect($addOnLines)->sum(fn (AddOnPriceLine $line) => $line->amountDue());

        return new PriceBreakdown(
            entryFee: $entryFee,
            entryCoverage: $entryCoverage,
            entryCoveredBy: $entryCoveredBy,
            addOnLines: $addOnLines,
            voucherCoverage: 0.0,
            amountPaid: $amountPaid,
        );
    }

    /**
     * A manager waiving this specific visit's entry fee (e.g. a volunteer
     * "House Sub" that night) — a per-visit override layered on top of
     * price(), the same shape as applyVoucher(), not baked into price()
     * itself: that's a property of member+event, this is a one-off
     * decision. Fully overrides whatever price() already computed for entry
     * (category comp, regular-subscription coverage, or nothing). Entry
     * only — add-on lines stay priced independently. See
     * docs/BLUEPRINT.md "Per-event comp".
     */
    public function applyEventComp(PriceBreakdown $breakdown): PriceBreakdown
    {
        return new PriceBreakdown(
            entryFee: $breakdown->entryFee,
            entryCoverage: $breakdown->entryFee,
            entryCoveredBy: EntryCoverageSource::EventComp,
            addOnLines: $breakdown->addOnLines,
            voucherCoverage: $breakdown->voucherCoverage,
            amountPaid: max(0.0, $breakdown->amountPaid - ($breakdown->entryFee - $breakdown->entryCoverage)),
        );
    }

    /**
     * Applies voucher credit on top of an already-priced breakdown. A
     * separate, explicit step from price() because it's opt-in and partial
     * at check-in, not a deterministic function of member+event — see
     * docs/BLUEPRINT.md "Vouchers". Always capped at both the
     * payer's available balance and what's still due; never goes negative.
     */
    public function applyVoucher(PriceBreakdown $breakdown, float $availableBalance, float $requestedAmount): PriceBreakdown
    {
        $applied = max(0.0, min($requestedAmount, $availableBalance, $breakdown->amountPaid));

        return new PriceBreakdown(
            entryFee: $breakdown->entryFee,
            entryCoverage: $breakdown->entryCoverage,
            entryCoveredBy: $breakdown->entryCoveredBy,
            addOnLines: $breakdown->addOnLines,
            voucherCoverage: $applied,
            amountPaid: $breakdown->amountPaid - $applied,
        );
    }
}
