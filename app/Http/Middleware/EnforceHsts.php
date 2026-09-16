<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceHsts
{
    /**
     * Tell the browser to only ever reach this app over HTTPS from here on,
     * once it's actually being served over HTTPS. Per RFC 6797 §7.2 a
     * browser must ignore the header when it arrives over plain HTTP, but
     * gating on Request::secure() here too keeps local dev (a plain-HTTP
     * Herd `.test` domain) and a fork that hasn't done its own TLS cutover
     * from ever emitting it.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
