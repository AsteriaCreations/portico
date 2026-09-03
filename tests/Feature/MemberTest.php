<?php

use App\Enums\PlanType;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('hasActiveSubscription matches only the same plan type and month', function () {
    $member = Member::factory()->create();
    $member->subscriptions()->create([
        'plan_type' => PlanType::Regular,
        'covered_month' => '2026-07-01',
        'amount_paid' => 60,
    ]);

    expect($member->hasActiveSubscription(PlanType::Regular, now()->parse('2026-07-01')))->toBeTrue()
        ->and($member->hasActiveSubscription(PlanType::Pool, now()->parse('2026-07-01')))->toBeFalse()
        ->and($member->hasActiveSubscription(PlanType::Regular, now()->parse('2026-08-01')))->toBeFalse();
});

test('isSubscriptionEligible is false below the attendance threshold with no manual flag', function () {
    $member = Member::factory()->create(['subscription_eligible' => false]);
    $event = Event::factory()->create();
    $member->attendance()->create([
        'event_id' => $event->id,
        'checked_in_at' => now(),
    ]);

    expect($member->isSubscriptionEligible())->toBeFalse();
});

test('isSubscriptionEligible becomes true once attended events reach the configured threshold', function () {
    MembershipSetting::current()->update(['subscription_eligibility_threshold' => 5]);

    $member = Member::factory()->create(['subscription_eligible' => false]);

    for ($i = 0; $i < 5; $i++) {
        $event = Event::factory()->create();
        $member->attendance()->create([
            'event_id' => $event->id,
            'checked_in_at' => now(),
        ]);
    }

    expect($member->isSubscriptionEligible())->toBeTrue();
});

test('prepaid attendance without a check-in time does not count toward eligibility', function () {
    MembershipSetting::current()->update(['subscription_eligibility_threshold' => 1]);

    $member = Member::factory()->create(['subscription_eligible' => false]);
    $event = Event::factory()->create();
    $member->attendance()->create([
        'event_id' => $event->id,
        'checked_in_at' => null,
    ]);

    expect($member->isSubscriptionEligible())->toBeFalse();
});

test('the manual subscription_eligible flag grants eligibility regardless of attendance count', function () {
    $member = Member::factory()->create(['subscription_eligible' => true]);

    expect($member->isSubscriptionEligible())->toBeTrue();
});

test('isOnProbation is true within the configured period based on date_vetted', function () {
    MembershipSetting::current()->update(['probation_period_days' => 90]);
    $member = Member::factory()->create([
        'date_vetted' => now()->subDays(30),
        'probation_override_start' => null,
    ]);

    expect($member->isOnProbation())->toBeTrue();
});

test('isOnProbation is false once the configured period has elapsed', function () {
    MembershipSetting::current()->update(['probation_period_days' => 90]);
    $member = Member::factory()->create([
        'date_vetted' => now()->subDays(120),
        'probation_override_start' => null,
    ]);

    expect($member->isOnProbation())->toBeFalse();
});

test('probation_override_start takes precedence over date_vetted', function () {
    MembershipSetting::current()->update(['probation_period_days' => 90]);
    $member = Member::factory()->create([
        'date_vetted' => now()->subDays(120),
        'probation_override_start' => now()->subDays(10),
    ]);

    expect($member->isOnProbation())->toBeTrue();
});

test('isOnProbation is false when neither date_vetted nor the override is set', function () {
    $member = Member::factory()->create([
        'date_vetted' => null,
        'probation_override_start' => null,
    ]);

    expect($member->isOnProbation())->toBeFalse();
});

test('nextMemberNumber is 1 when the table is empty', function () {
    expect(Member::nextMemberNumber())->toBe(1);
});

test('nextMemberNumber is one past the current highest, ignoring gaps', function () {
    Member::factory()->create(['member_number' => 5]);
    Member::factory()->create(['member_number' => 12]);
    Member::factory()->create(['member_number' => 8]);

    expect(Member::nextMemberNumber())->toBe(13);
});
