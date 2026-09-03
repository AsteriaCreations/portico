<?php

use App\Enums\PlanType;
use App\Enums\Role;
use App\Models\Attendance;
use App\Models\Member;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ManagerPerkService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new ManagerPerkService;
    $this->manager = User::factory()->create(['role' => Role::Manager]);
});

test('a manager can grant the perk to a member, creating a $0 regular subscription', function () {
    $beneficiary = Member::factory()->create(['subscription_eligible' => true]);

    $subscription = $this->service->grant($this->manager, $beneficiary);

    expect($subscription->plan_type)->toBe(PlanType::Regular)
        ->and($subscription->amount_paid)->toEqual(0)
        ->and($subscription->recorded_by)->toBe($this->manager->id)
        ->and($subscription->comp_source)->toBe(ManagerPerkService::CompSource)
        ->and($subscription->covered_month->toDateString())->toBe(now()->startOfMonth()->toDateString());
});

test('a manager cannot grant the perk twice in the same calendar month', function () {
    $first = Member::factory()->create(['subscription_eligible' => true]);
    $second = Member::factory()->create(['subscription_eligible' => true]);

    $this->service->grant($this->manager, $first);

    expect($this->service->isAvailable($this->manager))->toBeFalse();
    expect(fn () => $this->service->grant($this->manager, $second))
        ->toThrow(RuntimeException::class);

    expect(Subscription::where('member_id', $second->id)->exists())->toBeFalse();
});

test('the perk becomes available again in a new calendar month', function () {
    $beneficiary = Member::factory()->create(['subscription_eligible' => true]);
    $this->service->grant($this->manager, $beneficiary);

    expect($this->service->isAvailable($this->manager, now()))->toBeFalse()
        ->and($this->service->isAvailable($this->manager, now()->addMonthNoOverflow()))->toBeTrue();
});

test('the perk cannot be granted to a member who already has regular subscription coverage this month', function () {
    $beneficiary = Member::factory()->create(['subscription_eligible' => true]);
    Subscription::create([
        'member_id' => $beneficiary->id,
        'plan_type' => PlanType::Regular,
        'covered_month' => now()->startOfMonth()->toDateString(),
        'amount_paid' => 60,
    ]);

    expect(fn () => $this->service->grant($this->manager, $beneficiary))
        ->toThrow(RuntimeException::class);
});

test('a different manager has their own independent monthly perk', function () {
    $otherManager = User::factory()->create(['role' => Role::Manager]);
    $beneficiary = Member::factory()->create(['subscription_eligible' => true]);

    $this->service->grant($this->manager, $beneficiary);

    expect($this->service->isAvailable($otherManager))->toBeTrue();
});

test('the perk cannot be granted to a member who is not yet subscription-eligible', function () {
    $beneficiary = Member::factory()->create(['subscription_eligible' => false]);

    expect(fn () => $this->service->grant($this->manager, $beneficiary))
        ->toThrow(RuntimeException::class, 'This member is not yet subscription-eligible.');

    expect(Subscription::where('member_id', $beneficiary->id)->exists())->toBeFalse();
});

test('a member who reached the attendance threshold is eligible for the perk without the manual flag', function () {
    $beneficiary = Member::factory()->create(['subscription_eligible' => false]);
    Attendance::factory()
        ->count(config('membership.subscription_eligibility_threshold'))
        ->create(['member_id' => $beneficiary->id, 'checked_in_at' => now()]);

    $subscription = $this->service->grant($this->manager, $beneficiary);

    expect($subscription->member_id)->toBe($beneficiary->id);
});
