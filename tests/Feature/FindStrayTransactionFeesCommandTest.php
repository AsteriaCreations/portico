<?php

use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\AttendanceAddOn;
use App\Models\Member;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A visit that credit plus a voucher covered exactly, yet recorded a $0.50 fee. */
function strayFeeVisit(array $overrides = []): Attendance
{
    return Attendance::factory()->create([
        'entry_fee' => 20.30,
        'entry_coverage' => 20.00,
        'voucher_coverage' => 0.30,
        'amount_paid' => 0.50,
        'payment_method' => 'card',
        ...$overrides,
    ]);
}

test('lists a visit charged a fee with nothing due, and changes nothing', function () {
    $visit = strayFeeVisit();

    $this->artisan('attendance:find-stray-fees')
        ->expectsOutputToContain((string) $visit->member->username)
        ->expectsOutputToContain('1 check-in(s), 0.50 in total. Nothing was changed.')
        ->assertSuccessful();

    expect($visit->fresh()->amount_paid)->toEqual(0.50);
});

test('ignores visits where something was due or nothing was charged', function () {
    // Paid its full entry fee.
    Attendance::factory()->create(['entry_fee' => 40, 'amount_paid' => 40, 'payment_method' => 'cash']);
    // Paid a legitimate remainder after credit, with a fee on top.
    Attendance::factory()->create(['entry_fee' => 40, 'entry_coverage' => 25, 'amount_paid' => 15.50, 'payment_method' => 'card']);
    // Fully covered and nothing charged.
    Attendance::factory()->create(['entry_fee' => 20.30, 'entry_coverage' => 20.00, 'voucher_coverage' => 0.30, 'amount_paid' => 0, 'payment_method' => 'card']);
    // A prepay with no payment method.
    Attendance::factory()->create(['entry_fee' => 20, 'entry_coverage' => 20, 'amount_paid' => 5, 'payment_method' => null]);

    $this->artisan('attendance:find-stray-fees')
        ->expectsOutputToContain('No check-ins found with a transaction fee charged on nothing due.')
        ->assertSuccessful();
});

test('counts an add-on charge as something due', function () {
    $visit = Attendance::factory()->create([
        'entry_fee' => 20, 'entry_coverage' => 20, 'amount_paid' => 50.50, 'payment_method' => 'card',
    ]);
    AttendanceAddOn::factory()->create(['attendance_id' => $visit->id, 'add_on_id' => AddOn::factory()->create()->id, 'price' => 50]);

    $this->artisan('attendance:find-stray-fees')
        ->expectsOutputToContain('No check-ins found')
        ->assertSuccessful();
});

test('flags a same-day subscription paid the same way for a person to check', function () {
    $member = Member::factory()->create();
    $visit = strayFeeVisit(['member_id' => $member->id]);
    Subscription::factory()->create([
        'member_id' => $member->id,
        'payment_method' => 'card',
        'paid_on' => $visit->created_at->toDateString(),
    ]);

    $this->artisan('attendance:find-stray-fees')
        ->expectsOutputToContain('yes — check')
        ->assertSuccessful();
});
