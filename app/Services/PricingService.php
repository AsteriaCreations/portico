<?php

namespace App\Services;

use App\Enums\AddOnCoverageSource;
use App\Enums\EntryCoverageSource;
use App\Models\AddOn;
use App\Models\Event;
use App\Models\Member;
use App\Models\Plan;
use App\Models\PrivateRental;
use App\Support\Cents;
use Illuminate\Support\Collection;

/**
 * The single source of truth for what a member owes at an event. Entry is
 * priced on its own (host bypass, category comp — full, or reduced to the
 * event's comped_entry_fee — a private rental's paid-for spots, the Regular subscription's
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
        $entryFee = Cents::of($event->entry_fee);

        if ($member->category->is_comped) {
            $addOnLines = $subscribableAddOns
                ->map(function (AddOn $addOn) use ($event) {
                    $fee = $addOn->priceFor($event);

                    return $fee !== null ? new AddOnPriceLine($addOn, Cents::of($fee), Cents::of($fee), AddOnCoverageSource::Comp) : null;
                })
                ->filter()
                ->values()
                ->all();

            // An event can charge comped categories a reduced entry
            // instead of free (events.comped_entry_fee); add-ons stay
            // comped either way.
            $compedDue = $event->comped_entry_fee !== null ? min($entryFee, Cents::of($event->comped_entry_fee)) : 0;

            return new PriceBreakdown(
                entryFeeCents: $entryFee,
                entryCoverageCents: $entryFee - $compedDue,
                entryCoveredBy: EntryCoverageSource::Comp,
                addOnLines: $addOnLines,
                voucherCoverageCents: 0,
                amountPaidCents: $compedDue,
            );
        }

        $rental = $event->privateRental?->cancelled_at === null ? $event->privateRental : null;

        if ($entryFee > 0 && $event->host_id !== null && (int) $event->host_id === $member->id) {
            $entryCoverage = $entryFee;
            $entryCoveredBy = EntryCoverageSource::Host;
        } elseif ($entryFee > 0 && $rental !== null && $this->hasCoveredRentalSpot($rental, $member)) {
            $entryCoverage = $entryFee;
            $entryCoveredBy = EntryCoverageSource::PrivateRental;
        } elseif ($entryFee > 0 && $regularActive && $rental === null) {
            $credit = Cents::of(Plan::currentFor(AddOn::entry(), $event->event_date)?->credit);
            $entryCoverage = min($entryFee, $credit);
            $entryCoveredBy = EntryCoverageSource::RegularSubscription;
        } else {
            $entryCoverage = 0;
            $entryCoveredBy = EntryCoverageSource::None;
        }

        $addOnLines = [];
        foreach ($subscribableAddOns as $addOn) {
            $price = $addOn->priceFor($event);
            if ($price === null) {
                continue;
            }
            $fee = Cents::of($price);

            if (in_array($addOn->id, $addOnDayPassIds, true)) {
                $coverage = $fee;
                $coveredBy = AddOnCoverageSource::DayPass;
            } elseif (in_array($addOn->id, $activeAddOnSubscriptionIds, true)) {
                $credit = Plan::currentFor($addOn, $event->event_date)?->credit;
                $coverage = $credit !== null ? min($fee, Cents::of($credit)) : $fee;
                $coveredBy = AddOnCoverageSource::Subscription;
            } else {
                $coverage = 0;
                $coveredBy = AddOnCoverageSource::None;
            }

            $addOnLines[] = new AddOnPriceLine($addOn, $fee, $coverage, $coveredBy);
        }

        $amountPaid = ($entryFee - $entryCoverage) + array_sum(array_map(fn (AddOnPriceLine $line) => $line->amountDueCents(), $addOnLines));

        return new PriceBreakdown(
            entryFeeCents: $entryFee,
            entryCoverageCents: $entryCoverage,
            entryCoveredBy: $entryCoveredBy,
            addOnLines: $addOnLines,
            voucherCoverageCents: 0,
            amountPaidCents: $amountPaid,
        );
    }

    /**
     * Whether a spot the renter paid for is still free for $member: first
     * come, first served, counting everyone already checked in to the
     * rental (the renter included) other than $member. Past that, each
     * person pays the rental's per-extra-person charge (the event's entry
     * fee), which a subscription doesn't cover: it's the rental's charge,
     * not the club's entry. CheckInService prices under
     * CapacityService::lockForAdmission(), so two desks can't both take
     * the last spot.
     */
    private function hasCoveredRentalSpot(PrivateRental $rental, Member $member): bool
    {
        return $rental->spotsTaken(except: $member) < $rental->coveredSpots();
    }

    /**
     * A manager waiving this specific visit's entry fee (e.g. a volunteer
     * "House Sub" that night) — a per-visit override layered on top of
     * price(), the same shape as applyVoucher(), not baked into price()
     * itself: that's a property of member+event, this is a one-off
     * decision. Overrides whatever price() computed for an entry that still
     * owes something (nothing, a partial subscription credit, a comped
     * category's reduced fee). An entry already fully covered (subscription,
     * comped category, host) is returned unchanged: the comp waives nothing,
     * and the visit keeps its real coverage so showrunner headcount and comp
     * cost stay true. Entry only — add-on lines stay priced independently.
     * See docs/BLUEPRINT.md "Per-event comp".
     */
    public function applyEventComp(PriceBreakdown $breakdown): PriceBreakdown
    {
        if ($breakdown->entryCoverageCents >= $breakdown->entryFeeCents) {
            return $breakdown;
        }

        return new PriceBreakdown(
            entryFeeCents: $breakdown->entryFeeCents,
            entryCoverageCents: $breakdown->entryFeeCents,
            entryCoveredBy: EntryCoverageSource::EventComp,
            addOnLines: $breakdown->addOnLines,
            voucherCoverageCents: $breakdown->voucherCoverageCents,
            amountPaidCents: max(0, $breakdown->amountPaidCents - ($breakdown->entryFeeCents - $breakdown->entryCoverageCents)),
        );
    }

    /**
     * Applies voucher credit on top of an already-priced breakdown. A
     * separate, explicit step from price() because it's opt-in and partial
     * at check-in, not a deterministic function of member+event — see
     * docs/BLUEPRINT.md "Vouchers". Always capped at both the
     * payer's available balance and what's still due; never goes negative.
     * All three amounts are cents.
     */
    public function applyVoucher(PriceBreakdown $breakdown, int $availableBalanceCents, int $requestedCents): PriceBreakdown
    {
        $applied = max(0, min($requestedCents, $availableBalanceCents, $breakdown->amountPaidCents));

        return new PriceBreakdown(
            entryFeeCents: $breakdown->entryFeeCents,
            entryCoverageCents: $breakdown->entryCoverageCents,
            entryCoveredBy: $breakdown->entryCoveredBy,
            addOnLines: $breakdown->addOnLines,
            voucherCoverageCents: $applied,
            amountPaidCents: $breakdown->amountPaidCents - $applied,
        );
    }

    /**
     * How much each voucher balance pays, when a check-in draws on more than
     * one (the attendee's own, then another member's). Taken in the order
     * given: each source spends up to the smaller of what the desk asked
     * for and its balance, on entry/pool first and then on the
     * subscriptions bought in the same check-in, and nothing past what's
     * due. The desk's preview and CheckInService both use this, so the Due
     * line always matches what's spent. All amounts are cents.
     *
     * @param  list<array{requested: int, balance: int}>  $sources
     * @return list<array{entry: int, subscription: int}> one per source, same order
     */
    public function planVoucherDraws(int $entryDueCents, int $subscriptionDueCents, array $sources): array
    {
        $entryLeft = max(0, $entryDueCents);
        $subscriptionLeft = max(0, $subscriptionDueCents);

        return array_map(function (array $source) use (&$entryLeft, &$subscriptionLeft): array {
            $available = max(0, min($source['requested'], $source['balance']));
            $entry = min($available, $entryLeft);
            $subscription = min($available - $entry, $subscriptionLeft);
            $entryLeft -= $entry;
            $subscriptionLeft -= $subscription;

            return ['entry' => $entry, 'subscription' => $subscription];
        }, $sources);
    }
}
