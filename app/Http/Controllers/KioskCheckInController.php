<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\MembershipSetting;
use App\Services\KioskCheckInService;
use App\Support\ViteBuild;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KioskCheckInController extends Controller
{
    public const SCRIPT = 'resources/js/kiosk.js';

    /**
     * The scanner screen. With no event running it shows "closed" and asks
     * for no camera; it reloads itself to notice when an event starts.
     */
    public function show(): View
    {
        $settings = MembershipSetting::current();
        abort_unless($settings->kiosk_checkin_enabled, 404);

        return view('kiosk.scanner', [
            'orgName' => $settings->org_name,
            'isOpen' => Event::currentQuery()->get()->contains(fn (Event $event): bool => $event->isCurrentlyActive()),
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
}
