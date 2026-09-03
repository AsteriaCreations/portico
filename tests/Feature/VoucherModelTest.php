<?php

use App\Models\Attendance;
use App\Models\Member;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a member balance is the sum of their voucher ledger, never stored', function () {
    $member = Member::factory()->create();

    Voucher::factory()->for($member)->create(['amount' => 25]);
    Voucher::factory()->for($member)->create(['amount' => 25]);
    Voucher::factory()->for($member)->create(['amount' => -10, 'reason' => 'partial redemption']);

    expect($member->voucherBalance())->toEqual(40.0)
        ->and($member->vouchers()->count())->toBe(3);
});

test('a redemption can debit a different member than the one who attended', function () {
    $payer = Member::factory()->create();
    $attendee = Member::factory()->create();
    $attendance = Attendance::factory()->for($attendee)->create();

    Voucher::factory()->for($payer)->create(['amount' => 25]);
    Voucher::factory()->for($payer)->create([
        'amount' => -25,
        'reason' => "applied on behalf of {$attendee->username}'s entry",
        'attendance_id' => $attendance->id,
    ]);

    expect($payer->voucherBalance())->toEqual(0.0)
        ->and($attendee->voucherBalance())->toEqual(0.0)
        ->and($attendance->vouchers()->first()->member_id)->toBe($payer->id);
});

test('a voucher row is never updated, only offset by a new row', function () {
    $member = Member::factory()->create();
    $issued = Voucher::factory()->for($member)->create(['amount' => 25]);

    Voucher::factory()->for($member)->create([
        'amount' => -25,
        'reason' => "correction: voided #{$issued->id}, issued in error",
    ]);

    expect($member->voucherBalance())->toEqual(0.0)
        ->and($issued->fresh()->amount)->toEqual(25.0);
});
