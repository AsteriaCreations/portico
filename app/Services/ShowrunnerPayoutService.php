<?php

namespace App\Services;

use App\Enums\EntryCoverageSource;
use App\Enums\PayoutType;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\AttendanceAddOn;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\Plan;
use App\Models\ShowrunnerPayoutTier;
use App\Support\Cents;
use Illuminate\Database\Eloquent\Builder;

/**
 * Computes the showrunner's commission for an event — tiered on how many
 * paying attendees came through the door. Reporting/liability only: never
 * stored, never auto-issues the low-tier Voucher. Subscription-covered
 * ("SH") attendees only count toward the headcount/door total once the
 * event's entry fee exceeds the current Regular plan's credit — read from
 * Plan::currentFor(), never hardcoded, same rule PricingService::build()
 * already uses to cap subscription coverage itself. Amounts are integer
 * cents (see App\Support\Cents); a percentage payout is rounded once, to
 * the cent, half-up, so a total of payouts sums the rounded amounts.
 */
class ShowrunnerPayoutService
{
    public function calculate(Event $event): ShowrunnerPayoutResult
    {
        $creditThresholdCents = Cents::of(Plan::currentFor(AddOn::entry(), $event->event_date)?->credit);
        $includeSh = Cents::of($event->entry_fee) > $creditThresholdCents;

        $cashCount = $this->arrivedQuery($event)->where('entry_covered_by', EntryCoverageSource::None)->count();
        $shCount = $this->arrivedQuery($event)->where('entry_covered_by', EntryCoverageSource::RegularSubscription)->count();

        $cashRevenueCents = $this->sumEntryRevenueCents($event, EntryCoverageSource::None);
        $shRevenueCents = $this->sumEntryRevenueCents($event, EntryCoverageSource::RegularSubscription);

        $qualifyingBuckets = $includeSh
            ? [EntryCoverageSource::None, EntryCoverageSource::RegularSubscription]
            : [EntryCoverageSource::None];

        $qualifyingAttendanceIds = $this->arrivedQuery($event)
            ->whereIn('entry_covered_by', $qualifyingBuckets)
            ->pluck('id');

        // 'price' is the amount actually paid for the line (fee - coverage),
        // the same meaning whether the row is a flat add-on or a
        // subscribable one — see attendance_add_ons' own migration comment.
        $poolAddOn = AddOn::pool();
        $poolRevenueCents = $poolAddOn
            ? Cents::of(AttendanceAddOn::whereIn('attendance_id', $qualifyingAttendanceIds)->where('add_on_id', $poolAddOn->id)->sum('price'))
            : 0;

        // Flat (non-subscribable) add-on lines only — a subscribable line
        // (Pool, at launch) has its own covered_by value and its own
        // showrunner_door_includes_pool toggle above, so it must not also
        // be double-counted into the generic add-on total here.
        $addonRevenueCents = Cents::of(AttendanceAddOn::whereIn('attendance_id', $qualifyingAttendanceIds)->whereNull('covered_by')->sum('price'));

        $headcount = $cashCount + ($includeSh ? $shCount : 0);

        $settings = MembershipSetting::current();

        $doorTotalCents = $cashRevenueCents
            + ($includeSh ? $shRevenueCents : 0)
            + ($settings->showrunner_door_includes_pool ? $poolRevenueCents : 0)
            + ($settings->showrunner_door_includes_addons ? $addonRevenueCents : 0);

        // Greatest min_headcount at or below the actual headcount wins —
        // max_headcount is informational only, so a tier above the highest
        // configured min just keeps applying that top rate indefinitely.
        $tier = ShowrunnerPayoutTier::where('min_headcount', '<=', $headcount)
            ->orderByDesc('min_headcount')
            ->first();

        $payoutAmountCents = match (true) {
            $tier === null => null,
            $tier->payout_type === PayoutType::Voucher => Cents::of($tier->payout_value),
            default => Cents::percentOf($doorTotalCents, $tier->payout_value),
        };

        return new ShowrunnerPayoutResult(
            headcount: $headcount,
            includeSh: $includeSh,
            cashCount: $cashCount,
            shCount: $shCount,
            cashRevenueCents: $cashRevenueCents,
            shRevenueCents: $shRevenueCents,
            poolRevenueCents: $poolRevenueCents,
            addonRevenueCents: $addonRevenueCents,
            doorTotalCents: $doorTotalCents,
            tier: $tier,
            payoutAmountCents: $payoutAmountCents,
        );
    }

    private function arrivedQuery(Event $event): Builder
    {
        return Attendance::query()
            ->where('event_id', $event->id)
            ->whereNotNull('checked_in_at');
    }

    private function sumEntryRevenueCents(Event $event, EntryCoverageSource $source): int
    {
        return Cents::of($this->arrivedQuery($event)
            ->where('entry_covered_by', $source)
            ->selectRaw('COALESCE(SUM(entry_fee - entry_coverage), 0) as total')
            ->value('total'));
    }
}
