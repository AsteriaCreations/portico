<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Printable desk-reference cards (Check-In / Active Patrons / Record
// Departures) -- a standalone document with its own print layout, so it's
// served raw rather than wrapped in Filament's panel chrome. Linked from
// each of those screens' "How to use this screen" panel.
Route::get('/admin/desk-reference-cards', function () {
    return response()->file(storage_path('desk-reference-cards.html'));
})->middleware('auth')->name('desk-reference-cards');
