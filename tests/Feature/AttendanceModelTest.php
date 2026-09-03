<?php

use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\AttendanceAddOn;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('isOvernightStay is true when an attached add-on is flagged overnight', function () {
    $attendance = Attendance::factory()->create();
    $addOn = AddOn::factory()->create(['is_overnight' => true]);
    AttendanceAddOn::create([
        'attendance_id' => $attendance->id,
        'add_on_id' => $addOn->id,
        'name' => $addOn->name,
        'price' => $addOn->price,
        'is_overnight' => true,
    ]);

    expect($attendance->isOvernightStay())->toBeTrue();
});

test('isOvernightStay is false when add-ons exist but none are overnight-flagged', function () {
    $attendance = Attendance::factory()->create();
    $addOn = AddOn::factory()->create(['is_overnight' => false]);
    AttendanceAddOn::create([
        'attendance_id' => $attendance->id,
        'add_on_id' => $addOn->id,
        'name' => $addOn->name,
        'price' => $addOn->price,
        'is_overnight' => false,
    ]);

    expect($attendance->isOvernightStay())->toBeFalse();
});

test('isOvernightStay is false when there are no add-ons at all', function () {
    $attendance = Attendance::factory()->create();

    expect($attendance->isOvernightStay())->toBeFalse();
});

test('isOvernightStay gives the same answer whether or not the addOns relation is eager-loaded', function () {
    $attendance = Attendance::factory()->create();
    $addOn = AddOn::factory()->create(['is_overnight' => true]);
    AttendanceAddOn::create([
        'attendance_id' => $attendance->id,
        'add_on_id' => $addOn->id,
        'name' => $addOn->name,
        'price' => $addOn->price,
        'is_overnight' => true,
    ]);

    $notLoaded = Attendance::find($attendance->id);
    $loaded = Attendance::with('addOns')->find($attendance->id);

    expect($notLoaded->relationLoaded('addOns'))->toBeFalse()
        ->and($loaded->relationLoaded('addOns'))->toBeTrue()
        ->and($notLoaded->isOvernightStay())->toBeTrue()
        ->and($loaded->isOvernightStay())->toBeTrue();
});
