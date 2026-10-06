<?php

namespace App\Http\Middleware;

use App\Models\MembershipSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the kiosk's scan endpoint, the app's only write that skips staff
 * sign-in. With kiosk check-in off it doesn't exist (404); otherwise the
 * request must carry the tablet's device cookie, which holds the secret an
 * Admin issued (MembershipSetting::regenerateKioskDeviceSecret()) and is set
 * only when the tablet opens the setup link (KioskCheckInController::show()).
 * HttpOnly, so page script never sees it; SameSite=Strict, so another site
 * can't make the tablet send it. Defense in depth on top of the venue's
 * network isolation, not instead of it.
 */
class VerifyKioskDevice
{
    public const COOKIE = 'portico_kiosk_device';

    public function handle(Request $request, Closure $next): Response
    {
        $settings = MembershipSetting::current();

        abort_unless($settings->kiosk_checkin_enabled, 404);
        abort_unless($settings->kioskDeviceSecretMatches($request->cookie(self::COOKIE)), 403);

        return $next($request);
    }
}
