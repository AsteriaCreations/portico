<?php

namespace App\Services;

use App\Enums\AdmissionOutcome;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;

/**
 * Whether a member gets in — separate from what they pay (see PricingService).
 * Age is resolved against the event's date, not "today", so a member who
 * prepays for a future event is judged as of that event rather than now.
 *
 * on_probation deliberately does not affect this decision — the blueprint
 * flags it as reporting-only. The manual missing_paperwork flag does: it
 * produces a Capture, resolved by CheckIn::confirmPaperworkAction().
 */
class AdmissionPolicy
{
    public function decide(Member $member, Event $event): AdmissionDecision
    {
        $age = $member->dob?->diffInYears($event->event_date);
        $settings = MembershipSetting::current();

        if ($member->is_deceased) {
            return new AdmissionDecision(AdmissionOutcome::Block, __('Member marked deceased'));
        }

        if ($member->isCurrentlyBanned()) {
            if ($member->hasBanExceptionFor($event)) {
                return new AdmissionDecision(AdmissionOutcome::Warn, __('Banned — one-time exception granted for this event'), $member->ban_reason);
            }

            return new AdmissionDecision(AdmissionOutcome::Block, __('Do not admit'), $member->ban_reason);
        }

        if ($age !== null && $age < $settings->age_of_majority) {
            return new AdmissionDecision(AdmissionOutcome::Block, __('Under :age — no admittance', ['age' => $settings->age_of_majority]));
        }

        if ($member->on_watchlist) {
            return new AdmissionDecision(AdmissionOutcome::Warn, __('Notify :label', ['label' => MembershipSetting::watchlistNotifyLabel()]), $member->watchlist_reason);
        }

        if ($this->needsCapture($member)) {
            return new AdmissionDecision(AdmissionOutcome::Capture, __('Complete sign-up'));
        }

        if ($this->needsPaperworkCapture($member)) {
            return new AdmissionDecision(AdmissionOutcome::Capture, __('Missing paperwork — confirm on file before check-in'));
        }

        if ($this->isUnderAlcoholFlagAge($member, $event)) {
            return new AdmissionDecision(AdmissionOutcome::Flag, __('Under :age — no alcohol, mark hand', ['age' => $settings->alcohol_flag_age]));
        }

        return new AdmissionDecision(AdmissionOutcome::Ok, __('Cleared'));
    }

    /**
     * Whether the member is under the club's alcohol-flag age, as of the
     * event's date (or today with no event picked). False with no DOB on
     * file, or when the flag is disabled (alcohol_flag_age no higher than
     * age_of_majority). Also feeds the check-in desk's always-shown flag
     * list, so it's surfaced even when a higher-priority outcome wins decide().
     */
    public function isUnderAlcoholFlagAge(Member $member, ?Event $event = null): bool
    {
        $settings = MembershipSetting::current();

        if (! $member->dob || $settings->alcohol_flag_age <= $settings->age_of_majority) {
            return false;
        }

        return $member->dob->diffInYears($event?->event_date ?? today()) < $settings->alcohol_flag_age;
    }

    // Member-only, no event needed — the one AdmissionDecision outcome that
    // isn't event-dependent, so the check-in page can surface it the moment
    // a member is selected, before any event is picked.
    public function needsCapture(Member $member): bool
    {
        return $member->isProspective() && $this->hasIncompleteIdentity($member);
    }

    private function hasIncompleteIdentity(Member $member): bool
    {
        return blank($member->first_name) || blank($member->last_name) || blank($member->email);
    }

    // Member-only, same shape as needsCapture() -- missing_paperwork isn't
    // event-dependent either, so this can surface the moment a member is
    // selected. Deliberately its own check rather than folded into
    // needsCapture(): that one's resolving action (saveAndPromoteAction)
    // collects identity fields and promotes Prospective -> Irregular, which
    // is wrong for an already-Irregular member who just needs their
    // paperwork confirmed (CheckIn::confirmPaperworkAction, which also
    // records a Standard Paperwork signing in member_paperwork).
    public function needsPaperworkCapture(Member $member): bool
    {
        return $member->missing_paperwork;
    }
}
