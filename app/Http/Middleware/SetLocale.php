<?php

namespace App\Http\Middleware;

use App\Models\MembershipSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the installation's UI language (MembershipSetting::locale) to the
 * request. Registered in AdminPanelProvider as persistent middleware, so it
 * also runs on Livewire update requests -- not just the initial page load.
 *
 * A null or unrecognized value (no matching lang/*.json) leaves
 * config('app.locale') in force. Carbon follows app()->setLocale() on its
 * own; Number does not, so it is set explicitly -- and reset every request,
 * since it is a static that would otherwise outlive a changed setting.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        MembershipSetting::applyConfiguredLocale();

        return $next($request);
    }
}
