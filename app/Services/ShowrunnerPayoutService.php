<?php

namespace App\Services;

use App\Enums\EntryCoverageSource;
use App\Enums\PayoutType;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Plan;
use App\Models\ShowrunnerPayoutTier;
use App\Support\Cents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Computes the showrunner's commission for an event from how many paying
 * attendees came through the door. Reporting/liability only: never stored,
 * never auto-issues the small-event Voucher.
 *
 * Headcount: attendees who paid entry at the door, plus subscription-covered
 * ("SH") attendees -- live or imported from the old spreadsheet -- once the
 * event's entry fee exceeds the current Regular plan's credit -- read from Plan::currentFor(), never hardcoded, the same
 * rule PricingService::build() uses to cap subscription coverage. Comped
 * entries (a comped category such as staff, a per-event comp, the host) never
 * count.
 *
 * Tiers work like tax brackets over attendee numbers. A Percentage tier
 * covering attendees min..max pays its rate on the event's listed entry fee
 * for each attendee numbered inside it, so with 36-100 at 10% and 101+ at
 * 15%, 150 attendees pay 65 x fee x 10% plus 50 x fee x 15%. A Voucher tier
 * is flat: it applies only when the whole headcount falls inside it. Amounts
 * are integer cents (see App\Support\Cents); each tier's share is rounded
 * once, to the cent, half-up.
 */
class ShowrunnerPayoutService
{
    /**
     * Entry coverage that means "came in on a subscription". A legacy-import
     * row is the old spreadsheet's SH cell (or an SH member paying the
     * difference), so it counts the same way as a live subscription visit.
     */
    public const SUBSCRIPTION_SOURCES = [EntryCoverageSource::RegularSubscription, EntryCoverageSource::LegacyImport];

    public function calculate(Event $event): ShowrunnerPayoutResult
    {
        return $this->fromCounts(
            $event,
            cashCount: $this->arrivedQuery($event)->where('entry_covered_by', EntryCoverageSource::None)->count(),
            shCount: $this->arrivedQuery($event)->whereIn('entry_covered_by', self::SUBSCRIPTION_SOURCES)->count(),
            creditCents: Cents::of(Plan::currentFor(AddOn::entry(), $event->event_date)?->credit),
            tiers: ShowrunnerPayoutTier::orderBy('min_headcount')->get(),
        );
    }

    /**
     * The same calculation from counts the caller already has -- the payout
     * report loads them for a whole page of events at once (withCount) rather
     * than querying per event.
     *
     * @param  Collection<int, ShowrunnerPayoutTier>  $tiers  ordered by min_headcount
     */
    public function fromCounts(Event $event, int $cashCount, int $shCount, int $creditCents, Collection $tiers): ShowrunnerPayoutResult
    {
        $entryFeeCents = Cents::of($event->entry_fee);
        $includeSh = $entryFeeCents > $creditCents;
        $headcount = $cashCount + ($includeSh ? $shCount : 0);

        $bands = [];
        $voucherTier = null;

        foreach ($tiers as $tier) {
            if ($tier->payout_type === PayoutType::Voucher) {
                if ($tier->min_headcount <= $headcount && ($tier->max_headcount === null || $headcount <= $tier->max_headcount)) {
                    $voucherTier ??= $tier;
                }

                continue;
            }

            $attendees = $this->attendeesInBand($tier, $headcount);

            if ($attendees > 0) {
                $bands[] = new ShowrunnerPayoutBand(
                    tier: $tier,
                    attendees: $attendees,
                    amountCents: Cents::percentOf($attendees * $entryFeeCents, $tier->payout_value),
                );
            }
        }

        $payoutAmountCents = match (true) {
            $voucherTier !== null => Cents::of($voucherTier->payout_value),
            $bands !== [] => array_sum(array_map(fn (ShowrunnerPayoutBand $band): int => $band->amountCents, $bands)),
            // Tiers exist but the headcount doesn't reach a paying one: a
            // real $0. No tiers at all: unknown, so null.
            $tiers->isNotEmpty() => 0,
            default => null,
        };

        return new ShowrunnerPayoutResult(
            headcount: $headcount,
            includeSh: $includeSh,
            cashCount: $cashCount,
            shCount: $shCount,
            entryFeeCents: $entryFeeCents,
            bands: $bands,
            voucherTier: $voucherTier,
            payoutAmountCents: $payoutAmountCents,
        );
    }

    /**
     * How many of the headcount's attendees are numbered inside the tier's
     * min..max range (both ends inclusive; no max = no upper limit).
     */
    private function attendeesInBand(ShowrunnerPayoutTier $tier, int $headcount): int
    {
        $top = $tier->max_headcount === null ? $headcount : min($headcount, $tier->max_headcount);
        $bottom = max(1, $tier->min_headcount);

        return max(0, $top - $bottom + 1);
    }

    private function arrivedQuery(Event $event): Builder
    {
        return Attendance::query()
            ->where('event_id', $event->id)
            ->whereNotNull('checked_in_at');
    }
}
