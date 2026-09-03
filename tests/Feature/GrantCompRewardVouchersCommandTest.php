<?php

use App\Enums\Role;
use App\Models\Attendance;
use App\Models\CommandRun;
use App\Models\CompReason;
use App\Models\Event;
use App\Models\Member;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->systemUser = User::factory()->create([
        'email' => 'system@portico.internal',
        'role' => Role::Admin,
        'active' => false,
    ]);
});

test('grants a voucher for a comped, arrived attendee once the event has ended', function () {
    $reason = CompReason::factory()->create(['grants_voucher_amount' => 25]);
    $event = Event::factory()->create(['ends_at' => now()->subHour()]);
    $member = Member::factory()->create();
    $attendance = Attendance::factory()->for($event)->for($member)->create([
        'checked_in_at' => now()->subHours(2),
        'comp_reason_id' => $reason->id,
    ]);

    $this->artisan('vouchers:grant-comp-rewards')->assertSuccessful();

    $voucher = Voucher::where('attendance_id', $attendance->id)->firstOrFail();
    expect($voucher->member_id)->toBe($member->id)
        ->and($voucher->amount)->toEqual(25)
        ->and($voucher->recorded_by)->toBe($this->systemUser->id)
        ->and($voucher->reason)->toContain($reason->name);
});

test('does not grant before the event ends', function () {
    $reason = CompReason::factory()->create(['grants_voucher_amount' => 25]);
    $event = Event::factory()->create(['ends_at' => now()->addHour()]);
    $attendance = Attendance::factory()->for($event)->create([
        'checked_in_at' => now(),
        'comp_reason_id' => $reason->id,
    ]);

    $this->artisan('vouchers:grant-comp-rewards')->assertSuccessful();

    expect(Voucher::where('attendance_id', $attendance->id)->exists())->toBeFalse();
});

test('does not grant when the comp reason has no configured voucher amount', function () {
    $reason = CompReason::factory()->create(['grants_voucher_amount' => null]);
    $event = Event::factory()->create(['ends_at' => now()->subHour()]);
    $attendance = Attendance::factory()->for($event)->create([
        'checked_in_at' => now()->subHours(2),
        'comp_reason_id' => $reason->id,
    ]);

    $this->artisan('vouchers:grant-comp-rewards')->assertSuccessful();

    expect(Voucher::where('attendance_id', $attendance->id)->exists())->toBeFalse();
});

test('does not grant for a comp-listed member who never actually arrived', function () {
    $reason = CompReason::factory()->create(['grants_voucher_amount' => 25]);
    $event = Event::factory()->create(['ends_at' => now()->subHour()]);
    $attendance = Attendance::factory()->for($event)->create([
        'checked_in_at' => null,
        'comp_reason_id' => $reason->id,
    ]);

    $this->artisan('vouchers:grant-comp-rewards')->assertSuccessful();

    expect(Voucher::where('attendance_id', $attendance->id)->exists())->toBeFalse();
});

test('re-running the command is idempotent', function () {
    $reason = CompReason::factory()->create(['grants_voucher_amount' => 25]);
    $event = Event::factory()->create(['ends_at' => now()->subHour()]);
    $attendance = Attendance::factory()->for($event)->create([
        'checked_in_at' => now()->subHours(2),
        'comp_reason_id' => $reason->id,
    ]);

    $this->artisan('vouchers:grant-comp-rewards')->assertSuccessful();
    $this->artisan('vouchers:grant-comp-rewards')->assertSuccessful();

    expect(Voucher::where('attendance_id', $attendance->id)->count())->toBe(1);
});

test('a non-comped attendee never gets a comp reward voucher', function () {
    $event = Event::factory()->create(['ends_at' => now()->subHour()]);
    $attendance = Attendance::factory()->for($event)->create([
        'checked_in_at' => now()->subHours(2),
        'comp_reason_id' => null,
    ]);

    $this->artisan('vouchers:grant-comp-rewards')->assertSuccessful();

    expect(Voucher::where('attendance_id', $attendance->id)->exists())->toBeFalse();
});

test('a successful run records a CommandRun success, even with nothing to grant', function () {
    $this->artisan('vouchers:grant-comp-rewards')->assertSuccessful();

    $run = CommandRun::firstWhere('command', 'vouchers:grant-comp-rewards');
    expect($run)->not->toBeNull()
        ->and($run->last_success_at)->not->toBeNull()
        ->and($run->last_success_at->diffInSeconds(now()))->toBeLessThan(5);
});

test('a missing system user records a CommandRun failure', function () {
    $this->systemUser->delete();

    $this->artisan('vouchers:grant-comp-rewards')->assertFailed();

    $run = CommandRun::firstWhere('command', 'vouchers:grant-comp-rewards');
    expect($run)->not->toBeNull()
        ->and($run->last_failure_at)->not->toBeNull()
        ->and($run->last_failure_message)->toBe('system user not found');
});
