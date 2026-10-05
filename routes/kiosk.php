<?php

use App\Http\Controllers\KioskCheckInController;
use Illuminate\Support\Facades\Route;

// The kiosk tablet's endpoints. Registered in bootstrap/app.php outside the
// 'web' group: a device POST with no session and no CSRF token, guarded
// instead by VerifyKioskDevice (feature flag + device secret) and the
// 'kiosk' rate limit.
Route::post('/kiosk/scan', [KioskCheckInController::class, 'scan'])->name('kiosk.scan');
