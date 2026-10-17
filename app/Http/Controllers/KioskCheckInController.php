<?php

namespace App\Http\Controllers;

use App\Http\Middleware\VerifyKioskDevice;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Services\KioskCheckInService;
use App\Services\KioskCodeDisplay;
use App\Services\KioskQrCode;
use App\Support\ViteBuild;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Cookie;

class KioskCheckInController extends Controller
{
    public const SCRIPT = 'resources/js/kiosk.js';

    /** Long enough that a linked tablet never has to be set up again by expiry alone. */
    private const DEVICE_COOKIE_MINUTES = 60 * 24 * 365 * 5;

    /**
     * The scanner screen. Opened with the setup link (?key=…) it links this
     * tablet: a valid key becomes the device cookie and the key leaves the
     * address bar. With no event running it shows "closed" and asks for no
     * camera; it reloads itself to notice when an event starts.
     */
    public function show(Request $request): View|RedirectResponse
    {
        $settings = MembershipSetting::current();
        abort_unless($settings->kiosk_checkin_enabled, 404);

        $key = $request->query('key');
        if (is_string($key) && $settings->kioskDeviceSecretMatches($key)) {
            return redirect()->route('kiosk')->withCookie($this->deviceCookie($request, $key));
        }

        return view('kiosk.scanner', [
            'orgName' => $settings->org_name,
            'isLinked' => $settings->kioskDeviceSecretMatches($request->cookie(VerifyKioskDevice::COOKIE)),
            'badSetupLink' => $key !== null,
            'isOpen' => Event::anyCurrentlyActive(),
            'showsCodes' => $settings->kiosk_show_code_enabled,
            'isBuilt' => ViteBuild::has(self::SCRIPT),
            'script' => self::SCRIPT,
        ]);
    }

    public function scan(Request $request, KioskCheckInService $kiosk): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:64'],
        ]);

        return response()->json($kiosk->scan($validated['token'])->toArray());
    }

    /**
     * The tablet's poll for a code staff sent from the desk ("Show on
     * kiosk"). Handed out once: the next poll gets nothing. 204 while
     * nothing is waiting, or with the feature off.
     */
    public function showCode(KioskCodeDisplay $display, KioskQrCode $qr): JsonResponse|Response
    {
        $member = MembershipSetting::current()->kiosk_show_code_enabled ? $display->take() : null;

        if ($member === null) {
            return response()->noContent();
        }

        return response()->json([
            'name' => $member->displayName(),
            'qr' => $qr->pngDataUri($member->kiosk_token),
        ]);
    }

    private function deviceCookie(Request $request, string $secret): Cookie
    {
        return cookie(
            name: VerifyKioskDevice::COOKIE,
            value: $secret,
            minutes: self::DEVICE_COOKIE_MINUTES,
            path: '/kiosk',
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: 'strict',
        );
    }
}
