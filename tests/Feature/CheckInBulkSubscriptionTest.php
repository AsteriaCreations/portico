<?php

use App\Enums\PlanType;
use App\Enums\Role;
use App\Filament\Admin\Pages\CheckIn;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->irregular = Category::factory()->create(['name' => 'Irregular', 'is_comped' => false]);
    $this->user = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($this->user);

    Plan::create(['code' => PlanType::Regular, 'duration_months' => 1, 'price' => 60, 'credit' => 25, 'effective_from' => '2026-01-01']);
    Plan::create(['code' => PlanType::Regular, 'duration_months' => 3, 'price' => 175, 'credit' => null, 'effective_from' => '2026-01-01']);
    Plan::create(['code' => PlanType::Pool, 'duration_months' => 1, 'price' => 15, 'credit' => null, 'effective_from' => '2026-01-01']);
});

function bulkClearMember(Category $category, array $overrides = []): Member
{
    return Member::factory()->create(array_merge([
        'category_id' => $category->id,
        'dob' => '1990-01-01',
        'is_banned' => false,
        'on_watchlist' => false,
        'first_name' => 'Pat',
        'last_name' => 'Doe',
        'email' => 'pat@example.com',
    ], $overrides));
}

test('buying a 3-month bundle at check-in creates 3 rows split correctly', function () {
    $member = bulkClearMember($this->irregular, ['subscription_eligible' => true]);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->fillForm(['subscription_regular_duration' => '3'], 'pricingForm')
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
        ])
        ->assertHasNoActionErrors();

    $rows = Subscription::where('member_id', $member->id)->where('plan_type', PlanType::Regular)->orderBy('covered_month')->get();

    // Subtracting/adding from the 1st avoids Carbon's month-overflow quirk
    // on a 31st (now()->addMonth() can land mid-next-month instead of the
    // 1st) — see SubscriptionOverviewWidget's own test for the same gotcha.
    $expectedMonths = collect(range(0, 2))->map(fn (int $i) => now()->startOfMonth()->addMonths($i)->toDateString())->all();

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('covered_month')->map->toDateString()->all())->toBe($expectedMonths)
        ->and((float) $rows->sum('amount_paid'))->toEqual(175.0);
});

test('a bundle purchase whose window collides with existing coverage shifts forward, end to end', function () {
    $member = bulkClearMember($this->irregular, ['subscription_eligible' => true]);
    $member->subscriptions()->create([
        'plan_type' => PlanType::Regular,
        'covered_month' => now()->startOfMonth()->addMonth()->toDateString(),
        'amount_paid' => 60,
    ]);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->fillForm(['subscription_regular_duration' => '3'], 'pricingForm')
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
        ])
        ->assertHasNoActionErrors();

    $bundleRows = Subscription::where('member_id', $member->id)
        ->where('plan_type', PlanType::Regular)
        ->where('amount_paid', '!=', 60)
        ->orderBy('covered_month')
        ->get();

    // The existing row covers next month, so a 3-month bundle starting this
    // month would collide -- resolveStart() shifts the whole window forward
    // until no month in it collides, landing two months out instead of one.
    $expectedMonths = collect(range(2, 4))->map(fn (int $i) => now()->startOfMonth()->addMonths($i)->toDateString())->all();

    expect($bundleRows->pluck('covered_month')->map->toDateString()->all())->toBe($expectedMonths);
});

test('the 1-month option is still hidden outright (not shifted) once that exact month is covered', function () {
    $member = bulkClearMember($this->irregular, ['subscription_eligible' => true]);
    $member->subscriptions()->create([
        'plan_type' => PlanType::Regular,
        'covered_month' => now()->startOfMonth()->toDateString(),
        'amount_paid' => 60,
    ]);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20]);

    $instance = Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id]);

    $method = (new ReflectionClass(CheckIn::class))->getMethod('subscriptionOptions');
    $method->setAccessible(true);
    $options = $method->invoke($instance->instance(), PlanType::Regular, $member->fresh(), $event);

    expect($options)->not->toHaveKey(1)
        ->and($options)->toHaveKey(3);
});

test('a forged 4-month selection with no such plan configured leaves subscription untouched', function () {
    $member = bulkClearMember($this->irregular, ['subscription_eligible' => true]);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->fillForm(['subscription_regular_duration' => '4'], 'pricingForm')
        ->callAction('checkIn', data: [
            'checked_in_at' => now(),
        ])
        ->assertHasErrors(['pricingData.subscription_regular_duration']);

    expect(Subscription::where('member_id', $member->id)->exists())->toBeFalse();
});
