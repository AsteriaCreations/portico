<?php

use App\Models\MembershipSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
