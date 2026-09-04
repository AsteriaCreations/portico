<?php

use App\Models\AddOnDayPass;
use App\Models\Attendance;
use App\Models\MiscellaneousPayment;
use App\Models\Register;
use App\Models\Subscription;
use App\Models\User;
use App\Services\RegisterShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new RegisterShiftService;
    $this->register = Register::factory()->create();
    $this->user = User::factory()->create();
});

test('opening a shift creates a row attributed to the opener with the given opening count', function () {
    $shift = $this->service->openShift($this->register, $this->user, 150.00);

    expect($shift->register_id)->toBe($this->register->id)
        ->and($shift->opened_by)->toBe($this->user->id)
        ->and($shift->opening_count)->toEqual(150.0)
        ->and($shift->closed_at)->toBeNull();
});

test('opening a shift on a register that already has one open is rejected', function () {
    $this->service->openShift($this->register, $this->user, 100);

    expect(fn () => $this->service->openShift($this->register, $this->user, 50))
        ->toThrow(HttpException::class);
});

test('cashReceived sums only payment_method=cash rows tagged to that specific shift', function () {
    $shift = $this->service->openShift($this->register, $this->user, 0);
    $otherShift = $this->service->openShift(Register::factory()->create(), $this->user, 0);

    Attendance::factory()->create(['register_shift_id' => $shift->id, 'payment_method' => 'cash', 'amount_paid' => 20]);
    Attendance::factory()->create(['register_shift_id' => $shift->id, 'payment_method' => 'card', 'amount_paid' => 999]);
    Attendance::factory()->create(['register_shift_id' => $otherShift->id, 'payment_method' => 'cash', 'amount_paid' => 999]);
    Attendance::factory()->create(['register_shift_id' => null, 'payment_method' => 'cash', 'amount_paid' => 999]);
    Subscription::factory()->create(['register_shift_id' => $shift->id, 'payment_method' => 'cash', 'amount_paid' => 25]);
    Subscription::factory()->create(['register_shift_id' => $shift->id, 'payment_method' => 'venmo', 'amount_paid' => 999]);

    expect($this->service->cashReceived($shift))->toEqual(45.0);
});

test('drops reduce expectedClosingCount and multiple drops sum', function () {
    $shift = $this->service->openShift($this->register, $this->user, 100);
    Attendance::factory()->create(['register_shift_id' => $shift->id, 'payment_method' => 'cash', 'amount_paid' => 50]);

    $this->service->recordDrop($shift, $this->user, 30);
    $this->service->recordDrop($shift, $this->user, 20);

    expect($this->service->totalDrops($shift))->toEqual(50.0)
        ->and($this->service->expectedClosingCount($shift))->toEqual(100.0);
});

test('variance is null until closed, then closing_count minus expectedClosingCount', function () {
    $shift = $this->service->openShift($this->register, $this->user, 100);
    Attendance::factory()->create(['register_shift_id' => $shift->id, 'payment_method' => 'cash', 'amount_paid' => 50]);

    expect($this->service->variance($shift))->toBeNull();

    $exact = $this->service->closeShift($shift, $this->user, 150);
    expect($this->service->variance($exact))->toEqual(0.0);
});

test('variance is positive when the box counts over expected and negative when short', function () {
    $shift = $this->service->openShift($this->register, $this->user, 100);

    $over = $this->service->closeShift($shift, $this->user, 110);
    expect($this->service->variance($over))->toEqual(10.0);

    $short = $this->service->openShift(Register::factory()->create(), $this->user, 100);
    $short = $this->service->closeShift($short, $this->user, 90);
    expect($this->service->variance($short))->toEqual(-10.0);
});

test('closeShift records closed_by, closed_at, and closing_count', function () {
    $shift = $this->service->openShift($this->register, $this->user, 100);
    $closer = User::factory()->create();

    $closed = $this->service->closeShift($shift, $closer, 100, 'all clean');

    expect($closed->closed_by)->toBe($closer->id)
        ->and($closed->closed_at)->not->toBeNull()
        ->and($closed->closing_count)->toEqual(100.0)
        ->and($closed->notes)->toBe('all clean');
});

test('recording a drop on an already-closed shift is rejected', function () {
    $shift = $this->service->openShift($this->register, $this->user, 100);
    $this->service->closeShift($shift, $this->user, 100);

    expect(fn () => $this->service->recordDrop($shift, $this->user, 10))
        ->toThrow(HttpException::class);
});

test('closing an already-closed shift is rejected', function () {
    $shift = $this->service->openShift($this->register, $this->user, 100);
    $this->service->closeShift($shift, $this->user, 100);

    expect(fn () => $this->service->closeShift($shift, $this->user, 100))
        ->toThrow(HttpException::class);
});

test('opening a new shift is allowed again once the previous one is closed', function () {
    $first = $this->service->openShift($this->register, $this->user, 100);
    $this->service->closeShift($first, $this->user, 100);

    $second = $this->service->openShift($this->register, $this->user, 50);
    expect($second->id)->not->toBe($first->id);
});

test('recordMiscPayment creates a ledger row attributed to the shift and recorder', function () {
    $shift = $this->service->openShift($this->register, $this->user, 100);

    $payment = $this->service->recordMiscPayment($shift, $this->user, 25.0, 'cash', 'Donation from a member');

    expect($payment->register_shift_id)->toBe($shift->id)
        ->and($payment->payment_method)->toBe('cash')
        ->and($payment->amount)->toEqual(25.0)
        ->and($payment->notation)->toBe('Donation from a member')
        ->and($payment->recorded_by)->toBe($this->user->id);
});

test('recording a misc payment on an already-closed shift is rejected', function () {
    $shift = $this->service->openShift($this->register, $this->user, 100);
    $this->service->closeShift($shift, $this->user, 100);

    expect(fn () => $this->service->recordMiscPayment($shift, $this->user, 10, 'cash', 'test'))
        ->toThrow(HttpException::class);
});

test('cashReceived folds in a cash-flagged misc payment but not a non-cash one', function () {
    $shift = $this->service->openShift($this->register, $this->user, 0);

    $this->service->recordMiscPayment($shift, $this->user, 25, 'cash', 'Vendor payment');
    $this->service->recordMiscPayment($shift, $this->user, 999, 'venmo', 'Should not count');

    expect($this->service->cashReceived($shift))->toEqual(25.0)
        ->and($this->service->expectedClosingCount($shift))->toEqual(25.0);
});

test('revenueBreakdown splits event, subscription, and other totals across every payment method', function () {
    $shift = $this->service->openShift($this->register, $this->user, 0);

    Attendance::factory()->create(['register_shift_id' => $shift->id, 'payment_method' => 'cash', 'amount_paid' => 20]);
    Attendance::factory()->create(['register_shift_id' => $shift->id, 'payment_method' => 'venmo', 'amount_paid' => 15]);
    Subscription::factory()->create(['register_shift_id' => $shift->id, 'payment_method' => 'cash', 'amount_paid' => 25]);
    MiscellaneousPayment::factory()->create(['register_shift_id' => $shift->id, 'payment_method' => 'cash', 'amount' => 25, 'notation' => 'Donation']);
    MiscellaneousPayment::factory()->create(['register_shift_id' => $shift->id, 'payment_method' => 'venmo', 'amount' => 40, 'notation' => 'Rental']);

    expect($this->service->revenueBreakdown($shift))->toEqual([
        'event' => 35.0,
        'subscription' => 25.0,
        'other' => 65.0,
    ]);
});

test('cashReceived folds in a cash-paid pool day pass but not a non-cash one', function () {
    $shift = $this->service->openShift($this->register, $this->user, 0);

    AddOnDayPass::factory()->create(['register_shift_id' => $shift->id, 'payment_method' => 'cash', 'amount_paid' => 15]);
    AddOnDayPass::factory()->create(['register_shift_id' => $shift->id, 'payment_method' => 'venmo', 'amount_paid' => 999]);

    expect($this->service->cashReceived($shift))->toEqual(15.0);
});

test('revenueBreakdown folds a pool day pass into the other bucket', function () {
    $shift = $this->service->openShift($this->register, $this->user, 0);

    AddOnDayPass::factory()->create(['register_shift_id' => $shift->id, 'payment_method' => 'cash', 'amount_paid' => 15]);

    expect($this->service->revenueBreakdown($shift))->toEqual([
        'event' => 0.0,
        'subscription' => 0.0,
        'other' => 15.0,
    ]);
});
