<?php

namespace App\Http\Controllers;

use App\Models\MembershipSetting;
use App\Services\EventTimelineService;
use Illuminate\Contracts\View\View;

/**
 * The display screen for tonight's public timeline: a TV or tablet by the
 * door. It writes nothing and shows only Public categories, so, like the
 * kiosk's scanner screen, it needs only the feature flag, not a device
 * secret. It reloads itself every minute.
 */
class EventTimelineDisplayController extends Controller
{
    public const REFRESH_SECONDS = 60;

    public function show(EventTimelineService $timelines): View
    {
        $settings = MembershipSetting::current();
        abort_unless($settings->event_timelines_enabled, 404);

        return view('kiosk.timeline', [
            'orgName' => $settings->org_name,
            'logoUrl' => $settings->orgLogoUrl() ?? asset('images/portico-wheel-white.svg'),
            'timelines' => $timelines->publicDisplay(),
            'refreshSeconds' => self::REFRESH_SECONDS,
        ]);
    }
}
