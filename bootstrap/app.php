<?php

use App\Http\Middleware\EnforceHsts;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global, not panel-scoped, so every response -- assets, the health
        // check, the public welcome route -- carries it once served over
        // HTTPS, not just the admin panel.
        $middleware->append(EnforceHsts::class);

        // Routes outside the Filament panel (e.g. the desk-reference-cards
        // print page) still use the framework's 'auth' middleware alias, which
        // otherwise redirects a guest to an undefined 'login' route. Send them
        // to the panel's own login page instead.
        $middleware->redirectGuestsTo('/admin/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
