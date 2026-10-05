<?php

use App\Models\Member;
use App\Models\MembershipSetting;
use App\Services\KioskQrCode;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

// Everything lives in the admin panel; the bare address just sends people
// there (to the sign-in page if they aren't signed in).
Route::redirect('/', '/admin');

// Printable desk-reference cards (Check-In / Active Patrons / Record
// Departures) -- a standalone document with its own print layout, so it's
// served raw rather than wrapped in Filament's panel chrome. Linked from
// each of those screens' "How to use this screen" panel.
Route::get('/admin/desk-reference-cards', function () {
    return response()->file(storage_path('desk-reference-cards.html'));
})->middleware('auth')->name('desk-reference-cards');

// Printable kiosk QR card for one member, opened from the "Kiosk QR code"
// action (App\Filament\Concerns\ShowsKioskQrCode). Read-only: that action
// creates the code, so a member without one gets a 404 here.
Route::get('/admin/members/{member}/kiosk-card', function (Member $member) {
    abort_unless(Gate::allows('manage-kiosk-token'), 403);
    abort_if($member->kiosk_token === null, 404);

    return view('kiosk.qr-card', [
        'member' => $member,
        'orgName' => MembershipSetting::current()->org_name,
        'qrDataUri' => app(KioskQrCode::class)->pngDataUri($member->kiosk_token),
    ]);
})->middleware('auth')->name('kiosk-qr-card');
