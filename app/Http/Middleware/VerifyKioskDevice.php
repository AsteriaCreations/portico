<?php

namespace App\Http\Middleware;

use App\Models\MembershipSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the kiosk's scan endpoint, the app's only write that skips staff
 * sign-in. With kiosk check-in off it doesn't exist (404); otherwise the
 * request must carry the device secret an Admin issued
 * (MembershipSetting::regenerateKioskDeviceSecret()) in X-Kiosk-Secret.
 * Defense in depth on top of the venue's network isolation, not instead of
 * it.
 */
class VerifyKioskDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $settings = MembershipSetting::current();

        abort_unless($settings->kiosk_checkin_enabled, 404);
        abort_unless($settings->kioskDeviceSecretMatches($request->header('X-Kiosk-Secret')), 403);

        return $next($request);
    }
}
