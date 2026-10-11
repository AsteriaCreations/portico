<?php

namespace App\Services;

use App\Exceptions\GuestRefused;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Member;
use App\Models\MembershipSetting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A member registering a guest at the desk: a new Guest-category member
 * with sponsor_id set, so the sponsor stays accountable for them (see
 * MemberObserver). Only a sponsor who has arrived for tonight's event, can
 * sponsor guests (Member::canSponsorGuests()) and has guest allowance left.
 */
class GuestRegistrationService
{
    private const MAX_ATTEMPTS = 3;

    /**
     * Whether the desk should offer "Register a guest" for $sponsor with
     * this visit -- the same conditions register() enforces.
     */
    public function canRegister(?Member $sponsor, ?Attendance $sponsorAttendance): bool
    {
        return $sponsor
            && $sponsorAttendance?->checked_in_at
            && $sponsorAttendance->member_id === $sponsor->id
            && $sponsor->canSponsorGuests()
            && $sponsor->hasGuestAllowanceLeft($sponsorAttendance);
    }

    /**
     * The guest limit is re-checked with the sponsor's row locked, so two
     * registers can't both see "one left" and each add a guest. A taken
     * username is refused, not silently changed (staff chose it, so they
     * need to pick another); a member_number collision under concurrent
     * creates is retried, since MemberObserver assigns that one.
     *
     * @param  array{username: string, preferred_name: string, first_name: string, last_name: string, email?: ?string, email_opt_in?: bool, dob?: ?string}  $guest
     *
     * @throws GuestRefused
     */
    public function register(Member $sponsor, Attendance $sponsorAttendance, array $guest): Member
    {
        abort_unless($sponsorAttendance->member_id === $sponsor->id, 403);
        abort_unless($sponsor->canSponsorGuests(), 403);

        $guestCategory = Category::where('name', 'Guest')->firstOrFail();

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($sponsor, $sponsorAttendance, $guest, $guestCategory): Member {
                    Member::whereKey($sponsor->id)->lockForUpdate()->first();
                    $sponsorAttendance->refresh();

                    abort_unless($sponsorAttendance->checked_in_at, 403);

                    if (! $sponsor->hasGuestAllowanceLeft($sponsorAttendance)) {
                        throw new GuestRefused(__('Guest limit reached — :max per member per night. No guest was registered.', ['max' => MembershipSetting::current()->max_guests_per_night]));
                    }

                    if (Member::where('username', $guest['username'])->exists()) {
                        throw new GuestRefused(__('That username was just taken — please choose another.'));
                    }

                    return Member::create([
                        'username' => $guest['username'],
                        'preferred_name' => $guest['preferred_name'],
                        'first_name' => $guest['first_name'],
                        'last_name' => $guest['last_name'],
                        'email' => filled($guest['email'] ?? null) ? $guest['email'] : null,
                        'email_opt_in' => (bool) ($guest['email_opt_in'] ?? false),
                        'dob' => $guest['dob'] ?? null,
                        'category_id' => $guestCategory->id,
                        'sponsor_id' => $sponsor->id,
                        'notes' => "Guest of {$sponsor->displayName()}.",
                    ]);
                });
            } catch (UniqueConstraintViolationException $exception) {
                // Either member_number (retry: the next attempt reads a fresh
                // MAX) or a username claimed in the gap since the check above
                // (the next attempt's check refuses it with that message).
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw $exception;
                }
            }
        }
    }
}
