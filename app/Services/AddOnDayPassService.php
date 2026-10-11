<?php

namespace App\Services;

use App\Exceptions\DayPassRefused;
use App\Models\AddOn;
use App\Models\AddOnDayPass;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\PaymentMethod;
use App\Models\RegisterShift;
use App\Models\User;
use App\Support\Cents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A single-event pass for a priced_per_event add-on (Pool, at launch),
 * sold at the desk without a check-in. A flat add-on's price never varies
 * by event, so a "day pass" for one would be identical to just checking it
 * at check-in. Never creates a `subscriptions` row, so it isn't gated by
 * Member::isSubscriptionEligible(). See docs/BLUEPRINT.md "Fee pipeline".
 */
class AddOnDayPassService
{
    /**
     * The add-ons $member can buy a day pass for right now: currently
     * purchasable, paperwork on file (a lapsed Pool Waiver means no pool
     * use), and at least one event the pass could be for -- otherwise the
     * desk would offer "Buy Day Pass" onto an empty event list.
     *
     * @return Collection<int, AddOn>
     */
    public function sellableAddOns(Member $member): Collection
    {
        return $this->dayPassableAddOns()
            ->filter(fn (AddOn $addOn) => $member->canUseAddOn($addOn))
            ->filter(fn (AddOn $addOn) => $this->eventOptionsQuery($addOn)->exists())
            ->values();
    }

    /**
     * No point selling a day pass for an event that's already ended, or one
     * with no price for this add-on at all. Reads event.pool_fee directly,
     * same limitation as AddOn::priceFor() — Pool is the only
     * priced_per_event add-on today. Only tonight's events unless
     * day_pass_future_events_enabled: a pass sold for a later event would sit
     * in tonight's box with nothing tying it to that event.
     */
    public function eventOptionsQuery(AddOn $addOn): Builder
    {
        $events = MembershipSetting::current()->day_pass_future_events_enabled
            ? Event::currentOrFutureQuery()
            : Event::currentQuery();

        return $events->where('pool_fee', '>', 0);
    }

    /**
     * Records the pass at the event's add-on price, with the payment
     * method's transaction fee folded in (skipped when the price is $0).
     * Re-checks everything the desk's pickers filter on, so a forged
     * add-on, event or locked one-time method stops here.
     *
     * @throws DayPassRefused when $member already holds this pass
     */
    public function sell(
        Member $member,
        AddOn $addOn,
        Event $event,
        User $staff,
        string $paymentMethod,
        ?RegisterShift $registerShift = null,
    ): AddOnDayPass {
        abort_unless($this->dayPassableAddOns()->contains('id', $addOn->id), 403);
        abort_unless($member->canUseAddOn($addOn), 403);
        // The picker only offers unarchived, current (or, with the flag,
        // future) events with a price for this add-on.
        abort_if($event->isArchived(), 403);
        abort_unless($this->eventOptionsQuery($addOn)->whereKey($event->id)->exists(), 403);

        $isOneTime = in_array($paymentMethod, PaymentMethod::oneTimeCodes()->all(), true);
        abort_if($isOneTime && $member->hasUsedOneTimeMethod(), 422, 'This member has already used a one-time payment method.');

        $price = $addOn->priceFor($event);
        abort_unless($price !== null, 422);

        $priceCents = Cents::of($price);
        $feeCents = $priceCents > 0 ? Cents::of(PaymentMethod::feeFor($paymentMethod)) : 0;

        try {
            return DB::transaction(function () use ($member, $addOn, $event, $staff, $paymentMethod, $registerShift, $priceCents, $feeCents, $isOneTime): AddOnDayPass {
                $pass = AddOnDayPass::create([
                    'member_id' => $member->id,
                    'event_id' => $event->id,
                    'add_on_id' => $addOn->id,
                    'amount_paid' => Cents::toDecimal($priceCents + $feeCents),
                    'payment_method' => $paymentMethod,
                    'register_shift_id' => $registerShift?->id,
                    'recorded_by' => $staff->id,
                ]);

                if ($isOneTime) {
                    $member->recordOneTimeMethodUsage(PaymentMethod::where('code', $paymentMethod)->value('label'));
                }

                return $pass;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DayPassRefused(__(':username already has a :addon day pass for that event.', ['username' => $member->username, 'addon' => $addOn->name]));
        }
    }

    /**
     * @return Collection<int, AddOn>
     */
    private function dayPassableAddOns(): Collection
    {
        return AddOn::subscribable()
            ->where('priced_per_event', true)
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (AddOn $addOn) => $addOn->isCurrentlyPurchasable());
    }
}
