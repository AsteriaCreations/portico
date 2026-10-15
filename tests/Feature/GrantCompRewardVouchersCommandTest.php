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

test('the system user address is resolved from config', function () {
    config(['membership.system_user_email' => 'robot@myclub.example']);
    $customSystemUser = User::factory()->create([
        'email' => 'robot@myclub.example',
        'role' => Role::Admin,
        'active' => false,
    ]);

    $reason = CompReason::factory()->create(['grants_voucher_amount' => 25]);
    $event = Event::factory()->create(['ends_at' => now()->subHour()]);
    $attendance = Attendance::factory()->for($event)->create([
        'checked_in_at' => now()->subHours(2),
        'comp_reason_id' => $reason->id,
    ]);

    $this->artisan('vouchers:grant-comp-rewards')->assertSuccessful();

    $voucher = Voucher::where('attendance_id', $attendance->id)->firstOrFail();
    expect($voucher->recorded_by)->toBe($customSystemUser->id);
});

test('the missing-system-user error names the configured address', function () {
    config(['membership.system_user_email' => 'robot@myclub.example']);
    // beforeEach seeded the default-address user, not this one.

    $this->artisan('vouchers:grant-comp-rewards')
        ->expectsOutputToContain('robot@myclub.example')
        ->assertFailed();
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

test('a workday attendee who stays the minimum hours gets the workday voucher once it ends', function () {
    $event = Event::factory()->create(['name' => 'Spring Workday', 'ends_at' => now()->subHour(), 'workday_voucher_amount' => 20, 'workday_min_hours' => 4]);
    $attendance = Attendance::factory()->for($event)->create([
        'checked_in_at' => now()->subHours(6),
        'departed_at' => now()->subHours(2),
    ]);

    $this->artisan('vouchers:grant-comp-rewards')->assertSuccessful();

    $voucher = Voucher::where('attendance_id', $attendance->id)->firstOrFail();
    expect($voucher->member_id)->toBe($attendance->member_id)
        ->and($voucher->amount)->toEqual(20)
        ->and($voucher->recorded_by)->toBe($this->systemUser->id)
        ->and($voucher->reason)->toContain('Spring Workday');
});

test('a workday attendee short of the minimum hours, or with no recorded departure, gets nothing', function () {
    $event = Event::factory()->create(['ends_at' => now()->subHour(), 'workday_voucher_amount' => 20, 'workday_min_hours' => 4]);
    $short = Attendance::factory()->for($event)->create([
        'checked_in_at' => now()->subHours(5),
        'departed_at' => now()->subHours(2), // 3 hours
    ]);
    $neverDeparted = Attendance::factory()->for($event)->create([
        'checked_in_at' => now()->subHours(8),
        'departed_at' => null,
    ]);
    $noShow = Attendance::factory()->for($event)->create([
        'checked_in_at' => null,
        'departed_at' => null,
    ]);

    $this->artisan('vouchers:grant-comp-rewards')->assertSuccessful();

    expect(Voucher::whereIn('attendance_id', [$short->id, $neverDeparted->id, $noShow->id])->exists())->toBeFalse();
});

test('no workday voucher before the event ends, or for an ordinary event', function () {
    $ongoing = Event::factory()->create(['ends_at' => now()->addHour(), 'workday_voucher_amount' => 20, 'workday_min_hours' => 4]);
    $ordinary = Event::factory()->create(['ends_at' => now()->subHour()]);
    foreach ([$ongoing, $ordinary] as $event) {
        Attendance::factory()->for($event)->create([
            'checked_in_at' => now()->subHours(9),
            'departed_at' => now()->subHours(2),
        ]);
    }

    $this->artisan('vouchers:grant-comp-rewards')->assertSuccessful();

    expect(Voucher::count())->toBe(0);
});

test('the workday voucher is granted once, even when a voucher was spent at that visit', function () {
    $event = Event::factory()->create(['ends_at' => now()->subHour(), 'workday_voucher_amount' => 20, 'workday_min_hours' => 4]);
    $attendance = Attendance::factory()->for($event)->create([
        'checked_in_at' => now()->subHours(6),
        'departed_at' => now()->subHours(2),
    ]);
    Voucher::factory()->create([
        'member_id' => $attendance->member_id,
        'amount' => -10,
        'attendance_id' => $attendance->id,
        'recorded_by' => $this->systemUser->id,
    ]);

    $this->artisan('vouchers:grant-comp-rewards')->assertSuccessful();
    $this->artisan('vouchers:grant-comp-rewards')->assertSuccessful();

    expect(Voucher::where('attendance_id', $attendance->id)->where('amount', '>', 0)->count())->toBe(1);
});

test('a comped workday attendee gets one reward, not both', function () {
    $reason = CompReason::factory()->create(['grants_voucher_amount' => 25]);
    $event = Event::factory()->create(['ends_at' => now()->subHour(), 'workday_voucher_amount' => 20, 'workday_min_hours' => 4]);
    $attendance = Attendance::factory()->for($event)->create([
        'checked_in_at' => now()->subHours(6),
        'departed_at' => now()->subHours(2),
        'comp_reason_id' => $reason->id,
    ]);

    $this->artisan('vouchers:grant-comp-rewards')->assertSuccessful();

    expect(Voucher::where('attendance_id', $attendance->id)->pluck('amount')->all())->toEqual(['25.00']);
});
