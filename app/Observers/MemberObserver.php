<?php

namespace App\Observers;

use App\Enums\MemberStatusField;
use App\Models\Event;
use App\Models\Member;
use App\Models\MemberStatusChange;
use App\Models\MemberUsernameChange;

/**
 * Writes an audit-trail row whenever is_banned, on_watchlist, is_deceased,
 * missing_paperwork, is_active, or username changes, regardless of which
 * save path triggered it (Filament edit form, tinker, a future API) — the
 * model layer is the one place all of these are always dirty-checked no
 * matter how the write happened.
 */
class MemberObserver
{
    /**
     * Auto-assigns the next member_number for any staff-initiated create
     * (the admin Members form, guest registration at check-in) — nothing
     * ever types this in by hand anymore. Left alone when unauthenticated
     * (a console-driven import with no user to act as) so a member a
     * historical roster genuinely didn't number doesn't get a fabricated
     * one that matches nothing on a physical card, and left alone whenever
     * a caller supplies its own value (an import's own source-derived
     * number, factories in tests).
     */
    public function creating(Member $member): void
    {
        if (is_null($member->member_number) && auth()->check()) {
            $member->member_number = Member::nextMemberNumber();
        }
    }

    public function updating(Member $member): void
    {
        // changed_by is a required FK — there's no user to attribute a
        // console-driven change to (e.g. a bulk import), so skip logging
        // rather than violate the constraint.
        if (! auth()->check()) {
            return;
        }

        if ($member->isDirty('is_banned')) {
            MemberStatusChange::create([
                'member_id' => $member->id,
                'status' => MemberStatusField::Banned,
                'value' => $member->is_banned,
                'reason' => $member->ban_reason,
                'changed_by' => auth()->id(),
            ]);

            if ($member->is_banned) {
                $this->notifySponsorIfCurrentlyAccountable($member, 'banned', $member->ban_reason);
            }
        }

        if ($member->isDirty('on_watchlist')) {
            MemberStatusChange::create([
                'member_id' => $member->id,
                'status' => MemberStatusField::Watchlist,
                'value' => $member->on_watchlist,
                'reason' => $member->watchlist_reason,
                'changed_by' => auth()->id(),
            ]);

            if ($member->on_watchlist) {
                $this->notifySponsorIfCurrentlyAccountable($member, 'put on the watchlist', $member->watchlist_reason);
            }
        }

        if ($member->isDirty('is_deceased')) {
            MemberStatusChange::create([
                'member_id' => $member->id,
                'status' => MemberStatusField::Deceased,
                'value' => $member->is_deceased,
                'reason' => null,
                'changed_by' => auth()->id(),
            ]);
        }

        if ($member->isDirty('missing_paperwork')) {
            MemberStatusChange::create([
                'member_id' => $member->id,
                'status' => MemberStatusField::MissingPaperwork,
                'value' => $member->missing_paperwork,
                'reason' => null,
                'changed_by' => auth()->id(),
            ]);
        }

        if ($member->isDirty('is_active')) {
            MemberStatusChange::create([
                'member_id' => $member->id,
                'status' => MemberStatusField::Active,
                'value' => $member->is_active,
                'reason' => null,
                'changed_by' => auth()->id(),
            ]);
        }

        if ($member->isDirty('username')) {
            MemberUsernameChange::create([
                'member_id' => $member->id,
                'old_username' => $member->getOriginal('username'),
                'new_username' => $member->username,
                'changed_by' => auth()->id(),
            ]);
        }
    }

    /**
     * A guest's sponsor is accountable for them while either (a) the guest
     * has an attendance row tied to a currently-active event (Event::
     * currentQuery()'s own midnight-spanning buffer logic, not a raw
     * calendar-date check — a guest checked in just before midnight stays
     * covered), or (b) the guest is on an overnight stay (an attendance row
     * with a Private room rental/Sleepover-style add-on) and hasn't been
     * checked out yet (departed_at still null), however many calendar days
     * that takes. An infraction after the guest has left — same night or
     * overnight — stays on the guest alone. See docs/BLUEPRINT.md
     * "Still open" (overnight guest handling) and "Guests".
     */
    private function notifySponsorIfCurrentlyAccountable(Member $member, string $label, ?string $reason): void
    {
        if ($member->category?->name !== 'Guest' || ! $member->sponsor_id) {
            return;
        }

        $isAccountable = $member->attendance()
            ->whereNotNull('checked_in_at')
            ->where(fn ($query) => $query
                ->whereIn('event_id', Event::currentQuery()->pluck('id'))
                ->orWhere(fn ($overnight) => $overnight
                    ->whereNull('departed_at')
                    ->whereHas('addOns', fn ($addOns) => $addOns->where('is_overnight', true))))
            ->exists();

        if (! $isAccountable) {
            return;
        }

        $sponsor = Member::find($member->sponsor_id);
        if (! $sponsor) {
            return;
        }

        $line = today()->toDateString().": your guest {$member->username} was {$label} tonight".($reason ? " ({$reason})" : '').'.';
        $sponsor->notes = trim(($sponsor->notes ? $sponsor->notes."\n" : '').$line);
        $sponsor->saveQuietly();
    }
}
