<?php

use App\Models\MembershipSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('current returns the singleton row seeded by the migration', function () {
    expect(MembershipSetting::query()->count())->toBe(1);

    $setting = MembershipSetting::current();

    expect($setting->subscription_eligibility_threshold)->toBe((int) config('membership.subscription_eligibility_threshold'))
        ->and($setting->probation_period_days)->toBe((int) config('membership.probation_period_days'))
        ->and($setting->event_window_buffer_minutes)->toBe((int) config('membership.event_window_buffer_minutes'));
});

test('current recreates the row from config defaults if it is ever missing', function () {
    MembershipSetting::query()->delete();

    expect(MembershipSetting::query()->count())->toBe(0);

    $setting = MembershipSetting::current();

    expect(MembershipSetting::query()->count())->toBe(1)
        ->and($setting->subscription_eligibility_threshold)->toBe((int) config('membership.subscription_eligibility_threshold'))
        ->and($setting->probation_period_days)->toBe((int) config('membership.probation_period_days'))
        ->and($setting->venue_capacity)->toBe(config('membership.venue_capacity'))
        ->and($setting->event_window_buffer_minutes)->toBe((int) config('membership.event_window_buffer_minutes'));
});

test('current reflects updates rather than re-reading config', function () {
    MembershipSetting::current()->update(['subscription_eligibility_threshold' => 42]);

    expect(MembershipSetting::current()->subscription_eligibility_threshold)->toBe(42);
});

/**
 * @return int how many queries hit membership_settings while $callback ran
 */
function settingsQueriesDuring(callable $callback): int
{
    $count = 0;
    DB::listen(function ($query) use (&$count): void {
        if (str_contains($query->sql, 'membership_settings')) {
            $count++;
        }
    });
    $callback();

    return $count;
}

test('current reads the row once per request, however often it is called', function () {
    MembershipSetting::forgetCurrent();

    $queries = settingsQueriesDuring(function (): void {
        foreach (range(1, 10) as $call) {
            MembershipSetting::current();
        }
    });

    expect($queries)->toBe(1);
});

test('saving the row through any instance drops the cached copy', function () {
    MembershipSetting::current();

    MembershipSetting::query()->first()->update(['max_guests_per_night' => 7]);

    expect(MembershipSetting::current()->max_guests_per_night)->toBe(7);
});

test('a raw table change needs forgetCurrent to be seen', function () {
    MembershipSetting::current();
    DB::table('membership_settings')->update(['max_guests_per_night' => 9]);

    expect(MembershipSetting::current()->max_guests_per_night)->not->toBe(9);

    MembershipSetting::forgetCurrent();

    expect(MembershipSetting::current()->max_guests_per_night)->toBe(9);
});

test('formatMoney defaults to USD', function () {
    expect(MembershipSetting::formatMoney(1234.5))->toBe('$1,234.50');
});

test('formatMoney respects a club-configured currency', function () {
    MembershipSetting::current()->update(['currency' => 'EUR']);

    expect(MembershipSetting::formatMoney(1234.5))->toContain('1,234.50')
        ->and(MembershipSetting::formatMoney(1234.5))->not->toStartWith('$');
});
