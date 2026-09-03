<?php

namespace App\Services;

use App\Enums\AdmissionOutcome;
use App\Models\Event;
use App\Models\Member;

/**
 * Whether a member gets in — separate from what they pay (see PricingService).
 * Age is resolved against the event's date, not "today", so a member who
 * prepays for a future event is judged as of that event rather than now.
 *
 * on_probation and missing_paperwork deliberately do not affect this
 * decision — the blueprint flags them as reporting-only.
 */
class AdmissionPolicy
{
    public function decide(Member $member, Event $event): AdmissionDecision
    {
        $age = $member->dob?->diffInYears($event->event_date);

        if ($member->is_deceased) {
            return new AdmissionDecision(AdmissionOutcome::Block, 'Member marked deceased');
        }

        if ($member->isCurrentlyBanned()) {
            if ($member->hasBanExceptionFor($event)) {
                return new AdmissionDecision(AdmissionOutcome::Warn, 'Banned — one-time exception granted for this event', $member->ban_reason);
            }

            return new AdmissionDecision(AdmissionOutcome::Block, 'Do not admit', $member->ban_reason);
        }

        if ($age !== null && $age < 18) {
            return new AdmissionDecision(AdmissionOutcome::Block, 'Under 18 — no admittance');
        }

        if ($member->on_watchlist) {
            return new AdmissionDecision(AdmissionOutcome::Warn, 'Notify the staff channel', $member->watchlist_reason);
        }

        if ($this->needsCapture($member)) {
            return new AdmissionDecision(AdmissionOutcome::Capture, 'Complete sign-up');
        }

        if ($this->needsPaperworkCapture($member)) {
            return new AdmissionDecision(AdmissionOutcome::Capture, 'Missing paperwork — confirm on file before check-in');
        }

        if ($age !== null && $age < 21) {
            return new AdmissionDecision(AdmissionOutcome::Flag, 'Under 21 — no alcohol, mark hand');
        }

        return new AdmissionDecision(AdmissionOutcome::Ok, 'Cleared');
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
    // paperwork confirmed.
    public function needsPaperworkCapture(Member $member): bool
    {
        return $member->missing_paperwork;
    }
}
