<?php

namespace App\Services;

use App\Models\Member;
use Illuminate\Support\Facades\Cache;

/**
 * "Show on kiosk": staff send a member's kiosk code from the desk, and the
 * kiosk tablet (polling while it scans) shows it for the member to
 * photograph. One cache slot, since there is one tablet: the last code sent
 * wins, it's handed out once, and it expires on its own if the tablet never
 * collects it. Nothing is stored beyond that -- it's a display, not a record.
 */
class KioskCodeDisplay
{
    private const CACHE_KEY = 'kiosk.show-code';

    /** How long a sent code waits for the tablet, and how long the tablet shows it. */
    public const SECONDS = 60;

    /** Creates the member's code if they have none, then sends it. */
    public function queue(Member $member): void
    {
        Cache::put(self::CACHE_KEY, [
            'member_id' => $member->getKey(),
            'token' => $member->ensureKioskToken(),
        ], self::SECONDS);
    }

    /**
     * The waiting member, removed from the slot. Null if nothing is waiting,
     * or if their code was replaced since it was sent (a lost card must
     * never reappear on the tablet).
     */
    public function take(): ?Member
    {
        $waiting = Cache::pull(self::CACHE_KEY);

        if (! is_array($waiting)) {
            return null;
        }

        $member = Member::find($waiting['member_id'] ?? null);

        return $member !== null && $member->kiosk_token !== null && hash_equals($member->kiosk_token, (string) ($waiting['token'] ?? ''))
            ? $member
            : null;
    }
}
