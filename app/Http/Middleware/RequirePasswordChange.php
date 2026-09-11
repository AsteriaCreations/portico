<?php

namespace App\Http\Middleware;

use App\Filament\Admin\Pages\ChangePassword;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces UserForm's "Require password change at next login" toggle. Registered
 * in AdminPanelProvider's authMiddleware -- runs on every authenticated panel
 * route but not login/register/password-reset, so there's no redirect loop on
 * the login page itself. The change-password page and the logout route are the
 * only two routes left reachable while the flag is set, so a flagged user can
 * still get out without changing their password, just not do anything else.
 */
class RequirePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->must_change_password) {
            return $next($request);
        }

        // Hardcoding the "admin" panel id here mirrors AdminPanelProvider's own
        // ->id('admin') -- this app has exactly one panel.
        if ($request->routeIs(ChangePassword::getRouteName()) || $request->routeIs('filament.admin.auth.logout')) {
            return $next($request);
        }

        return redirect()->to(ChangePassword::getUrl());
    }
}
