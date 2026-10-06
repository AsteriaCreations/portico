<?php

use App\Http\Controllers\KioskCheckInController;
use App\Http\Middleware\VerifyKioskDevice;
use Illuminate\Support\Facades\Route;

// The kiosk tablet's endpoints. Registered in bootstrap/app.php outside the
// 'web' group (no session, no CSRF token) under the 'kiosk' rate limit.

// The scanner screen. It writes nothing and holds no secret -- the tablet
// keeps the device secret in its own storage -- so it needs only the flag
// (checked in the controller), not VerifyKioskDevice.
Route::get('/kiosk', [KioskCheckInController::class, 'show'])->name('kiosk');

// Every write: feature flag + device secret.
Route::post('/kiosk/scan', [KioskCheckInController::class, 'scan'])
    ->middleware(VerifyKioskDevice::class)
    ->name('kiosk.scan');
