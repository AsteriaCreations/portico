<?php

namespace App\Services;

use App\Models\Event;
use App\Models\IssueReport;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Report a problem": saves what the Check-In Desk is showing so a fault can
 * be reproduced later, with every personal detail left out. The snapshot is
 * built from an allow-list -- a field is copied only because it is named
 * here -- so a column added to members later never leaks into it by default.
 *
 * Left out: names, username, email, member number, date of birth (an age in
 * whole years stands in), notes, and the ban and watchlist reasons (the
 * AdmissionDecision's $reason). A member is a keyed pseudonym (memberRef()):
 * the same member always gets the same one, so two reports about one person
 * line up, but only this installation's APP_KEY can match it back
 * (findMemberByRef()).
 */
class IssueReportService
{
    /**
     * pricingData keys copied as they are. Anything not named here, or in
     * deskSelections(), is dropped and listed by name only.
     */
    private const SAFE_SELECTION_KEYS = ['add_on_ids', 'comp_entry', 'comp_reason_id', 'apply_voucher', 'voucher_amount'];

    /**
     * @param  array<string, mixed>  $pricingData  the desk's live choices (CheckIn::$pricingData)
     * @param  array<string, mixed>  $desk  page-only state: training mode, register, check-in closed, Due
     */
    public function reportFromDesk(
        User $staff,
        string $note,
        ?Member $member,
        ?Event $event,
        array $pricingData,
        ?PriceBreakdown $livePrice,
        array $desk,
    ): IssueReport {
        $snapshot = [
            'page' => 'check-in',
            'captured_at' => now()->toIso8601String(),
            'timezone' => config('app.timezone'),
            'schema_version' => DB::table('migrations')->max('migration'),
            'staff_role' => $staff->role->value,
            'desk' => $desk,
            'selections' => $this->deskSelections($pricingData),
            'event' => $event ? $this->eventSnapshot($event) : null,
            'member' => $member ? $this->memberSnapshot($member, $event) : null,
            'admission' => $member && $event ? $this->admissionSnapshot($member, $event) : null,
            'price_before_choices' => $member && $event ? $this->priceSnapshot(app(PricingService::class)->price($member, $event)) : null,
            'price_with_choices' => $livePrice ? $this->priceSnapshot($livePrice) : null,
            'existing_visit' => $member && $event ? $this->existingVisitSnapshot($member, $event) : null,
            'capacity' => [
                'occupancy_today' => app(CapacityService::class)->occupancy(today()),
                'capacity' => app(CapacityService::class)->capacity(),
            ],
            'settings' => $this->settingsSnapshot(),
        ];

        return IssueReport::create([
            'page' => 'check-in',
            'note' => $this->scrubNote($note, $member),
            'snapshot' => $snapshot,
            'reported_by' => $staff->id,
        ]);
    }

    /**
     * A stable, non-reversible stand-in for a member id: an HMAC keyed on
     * APP_KEY, so it can't be guessed from the id alone.
     */
    public function memberRef(int $memberId): string
    {
        return 'm_'.substr(hash_hmac('sha256', 'member:'.$memberId, (string) config('app.key')), 0, 16);
    }

    /**
     * The member a pseudonym stands for, on this installation only. Hashes
     * every member id in turn -- a club-sized roster is a few thousand
     * HMACs, well under a second.
     */
    public function findMemberByRef(string $ref): ?Member
    {
        foreach (Member::query()->pluck('id') as $id) {
            if (hash_equals($this->memberRef($id), $ref)) {
                return Member::find($id);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $pricingData
     * @return array<string, mixed>
     */
    private function deskSelections(array $pricingData): array
    {
        $kept = [];
        $dropped = [];

        foreach ($pricingData as $key => $value) {
            if (in_array($key, self::SAFE_SELECTION_KEYS, true) || str_starts_with($key, 'subscription_')) {
                $kept[$key] = $value;
            } elseif ($key === 'voucher_payer_id') {
                $kept[$key] = filled($value) ? $this->memberRef((int) $value) : null;
            } elseif ($key === 'voucher_reason') {
                $kept['voucher_reason_filled'] = filled($value);
            } else {
                $dropped[] = $key;
            }
        }

        if ($dropped !== []) {
            $kept['_left_out'] = $dropped;
        }

        return $kept;
    }

    /**
     * @return array<string, mixed>
     */
    private function eventSnapshot(Event $event): array
    {
        return [
            'id' => $event->id,
            'name' => $event->name,
            'type' => $event->eventType?->name,
            'event_date' => $event->event_date?->toDateString(),
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'checkin_closes_at' => $event->checkin_closes_at?->toIso8601String(),
            'entry_fee' => $event->entry_fee,
            'comped_entry_fee' => $event->comped_entry_fee,
            'pool_fee' => $event->pool_fee,
            'door_prepay_enabled' => (bool) $event->door_prepay_enabled,
            'has_showrunner' => $event->showrunner_id !== null,
            'has_host' => $event->host_id !== null,
            'is_currently_active' => $event->isCurrentlyActive(),
            'is_check_in_closed' => $event->isCheckInClosed(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function memberSnapshot(Member $member, ?Event $event): array
    {
        $coveredMonth = ($event?->event_date ?? today())->copy()->startOfMonth();

        return [
            'ref' => $this->memberRef($member->id),
            'category' => $member->category?->name,
            'age_years' => $member->dob ? (int) floor($member->dob->diffInYears($event?->event_date ?? today())) : null,
            'has_dob' => $member->dob !== null,
            'is_active' => (bool) $member->is_active,
            'has_email' => filled($member->email),
            'has_sponsor' => $member->sponsor_id !== null,
            'sponsor_ref' => $member->sponsor_id ? $this->memberRef($member->sponsor_id) : null,
            'is_event_host' => $event && $event->host_id === $member->id,
            'is_event_showrunner' => $event && $event->showrunner_id === $member->id,
            'subscription_eligible_flag' => (bool) $member->subscription_eligible,
            'eligibility_attendance_count' => $member->eligibilityAttendanceCount(),
            'is_subscription_eligible' => $member->isSubscriptionEligible(),
            'is_first_visit' => $member->isFirstVisit(),
            'is_on_probation' => $member->isOnProbation(),
            'has_probation_override' => $member->probation_override_start !== null,
            'on_watchlist' => (bool) $member->on_watchlist,
            'is_on_watchlist_probation' => $member->isOnWatchlistProbation(),
            'is_banned_flag' => (bool) $member->is_banned,
            'banned_until' => $member->banned_until?->toDateString(),
            'is_currently_banned' => $member->isCurrentlyBanned(),
            'has_ban_exception_for_event' => $event ? $member->hasBanExceptionFor($event) : null,
            'missing_paperwork' => (bool) $member->missing_paperwork,
            'is_deceased' => (bool) $member->is_deceased,
            'voucher_balance' => $member->voucherBalance(),
            'has_used_one_time_method' => $member->hasUsedOneTimeMethod(),
            'subscriptions_for_event_month' => $member->subscriptions()
                ->whereDate('covered_month', $coveredMonth)
                ->with('addOn')
                ->get()
                ->map(fn ($subscription): array => [
                    'add_on' => $subscription->addOn?->name,
                    'amount_paid' => $subscription->amount_paid,
                    'voucher_coverage' => $subscription->voucher_coverage,
                    'comp_source' => $subscription->comp_source,
                    'payment_method' => $subscription->payment_method,
                ])
                ->all(),
        ];
    }

    /**
     * The outcome and the message staff saw, never $reason: that is the
     * ban or watchlist reason itself.
     *
     * @return array<string, mixed>
     */
    private function admissionSnapshot(Member $member, Event $event): array
    {
        $decision = app(AdmissionPolicy::class)->decide($member, $event);

        return [
            'outcome' => $decision->outcome->value,
            'message' => $decision->message,
            'had_reason' => filled($decision->reason),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function priceSnapshot(PriceBreakdown $breakdown): array
    {
        return [
            'entry_fee_cents' => $breakdown->entryFeeCents,
            'entry_coverage_cents' => $breakdown->entryCoverageCents,
            'entry_covered_by' => $breakdown->entryCoveredBy->value,
            'voucher_coverage_cents' => $breakdown->voucherCoverageCents,
            'amount_paid_cents' => $breakdown->amountPaidCents,
            'add_on_lines' => array_map(fn (AddOnPriceLine $line): array => [
                'add_on' => $line->addOn->name,
                'fee_cents' => $line->feeCents,
                'coverage_cents' => $line->coverageCents,
                'covered_by' => $line->coveredBy->value,
            ], $breakdown->addOnLines),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function existingVisitSnapshot(Member $member, Event $event): ?array
    {
        $visit = $member->attendance()->where('event_id', $event->id)->first();

        if (! $visit) {
            return null;
        }

        return [
            'checked_in_at' => $visit->checked_in_at?->toIso8601String(),
            'departed_at' => $visit->departed_at?->toIso8601String(),
            'entry_fee' => $visit->entry_fee,
            'entry_coverage' => $visit->entry_coverage,
            'entry_covered_by' => $visit->entry_covered_by,
            'voucher_coverage' => $visit->voucher_coverage,
            'amount_paid' => $visit->amount_paid,
            'payment_method' => $visit->payment_method,
            'is_comped' => $visit->comp_reason_id !== null,
        ];
    }

    /**
     * Every on/off flag and number from Feature Flags and Membership
     * Settings. Free text (house policy wording, the club's name, the
     * deploy task) is left out.
     *
     * @return array<string, bool|int|float>
     */
    private function settingsSnapshot(): array
    {
        return collect(MembershipSetting::current()->attributesToArray())
            ->filter(fn (mixed $value): bool => is_bool($value) || is_int($value) || is_float($value))
            ->except(['id'])
            ->all();
    }

    /**
     * Staff are asked not to type names, but in case they do: the selected
     * member's own identifiers become "[member]".
     */
    private function scrubNote(string $note, ?Member $member): string
    {
        if (! $member) {
            return $note;
        }

        $identifiers = collect([
            $member->email, $member->username, $member->preferred_name,
            $member->first_name, $member->last_name, $member->member_number,
        ])
            ->filter(fn (mixed $value): bool => filled($value) && mb_strlen((string) $value) >= 3)
            ->map(fn (mixed $value): string => (string) $value)
            ->sortByDesc(fn (string $value): int => mb_strlen($value))
            ->values();

        foreach ($identifiers as $identifier) {
            $note = (string) preg_replace('/\b'.preg_quote($identifier, '/').'\b/iu', '[member]', $note);
        }

        return $note;
    }
}
