<?php

namespace App\Services;

use App\Enums\EntryCoverageSource;
use App\Enums\PayoutType;
use App\Enums\PlanType;
use App\Models\Attendance;
use App\Models\AttendanceAddOn;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\Plan;
use App\Models\ShowrunnerPayoutTier;
use Illuminate\Database\Eloquent\Builder;

/**
 * Computes the showrunner's commission for an event — tiered on how many
 * paying attendees came through the door. Reporting/liability only: never
 * stored, never auto-issues the low-tier Voucher. Subscription-covered
 * ("SH") attendees only count toward the headcount/door total once the
 * event's entry fee exceeds the current Regular plan's credit — read from
 * Plan::currentFor(), never hardcoded, same rule PricingService::build()
 * already uses to cap subscription coverage itself.
 */
class ShowrunnerPayoutService
{
    public function calculate(Event $event): ShowrunnerPayoutResult
    {
        $creditThreshold = (float) (Plan::currentFor(PlanType::Regular, $event->event_date)?->credit ?? 0);
        $includeSh = (float) $event->entry_fee > $creditThreshold;

        $cashCount = $this->arrivedQuery($event)->where('entry_covered_by', EntryCoverageSource::None)->count();
        $shCount = $this->arrivedQuery($event)->where('entry_covered_by', EntryCoverageSource::RegularSubscription)->count();

        $cashRevenue = $this->sumEntryRevenue($event, EntryCoverageSource::None);
        $shRevenue = $this->sumEntryRevenue($event, EntryCoverageSource::RegularSubscription);

        $qualifyingBuckets = $includeSh
            ? [EntryCoverageSource::None, EntryCoverageSource::RegularSubscription]
            : [EntryCoverageSource::None];

        $poolRevenue = (float) $this->arrivedQuery($event)
            ->whereIn('entry_covered_by', $qualifyingBuckets)
            ->selectRaw('COALESCE(SUM(pool_fee - pool_coverage), 0) as total')
            ->value('total');

        $qualifyingAttendanceIds = $this->arrivedQuery($event)
            ->whereIn('entry_covered_by', $qualifyingBuckets)
            ->pluck('id');

        $addonRevenue = (float) AttendanceAddOn::whereIn('attendance_id', $qualifyingAttendanceIds)->sum('price');

        $headcount = $cashCount + ($includeSh ? $shCount : 0);

        $settings = MembershipSetting::current();

        $doorTotal = $cashRevenue
            + ($includeSh ? $shRevenue : 0.0)
            + ($settings->showrunner_door_includes_pool ? $poolRevenue : 0.0)
            + ($settings->showrunner_door_includes_addons ? $addonRevenue : 0.0);

        // Greatest min_headcount at or below the actual headcount wins —
        // max_headcount is informational only, so a tier above the highest
        // configured min just keeps applying that top rate indefinitely.
        $tier = ShowrunnerPayoutTier::where('min_headcount', '<=', $headcount)
            ->orderByDesc('min_headcount')
            ->first();

        $payoutAmount = match (true) {
            $tier === null => null,
            $tier->payout_type === PayoutType::Voucher => (float) $tier->payout_value,
            default => $doorTotal * ((float) $tier->payout_value / 100),
        };

        return new ShowrunnerPayoutResult(
            headcount: $headcount,
            includeSh: $includeSh,
            cashCount: $cashCount,
            shCount: $shCount,
            cashRevenue: $cashRevenue,
            shRevenue: $shRevenue,
            poolRevenue: $poolRevenue,
            addonRevenue: $addonRevenue,
            doorTotal: $doorTotal,
            tier: $tier,
            payoutAmount: $payoutAmount,
        );
    }

    private function arrivedQuery(Event $event): Builder
    {
        return Attendance::query()
            ->where('event_id', $event->id)
            ->whereNotNull('checked_in_at');
    }

    private function sumEntryRevenue(Event $event, EntryCoverageSource $source): float
    {
        return (float) $this->arrivedQuery($event)
            ->where('entry_covered_by', $source)
            ->selectRaw('COALESCE(SUM(entry_fee - entry_coverage), 0) as total')
            ->value('total');
    }
}
