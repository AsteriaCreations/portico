<?php

use App\Enums\AddOnKind;
use App\Enums\Role;
use App\Filament\Admin\Resources\Subscriptions\Pages\CreateSubscription;
use App\Filament\Admin\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Models\AddOn;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['active' => true]));
    $this->entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
});

test('subscriptions list page renders', function () {
    Subscription::factory()->count(3)->create();

    Livewire::test(ListSubscriptions::class)->assertSuccessful();
});

test('a subscription can be created and covered_month is floored to the first of the month', function () {
    $member = Member::factory()->create(['subscription_eligible' => true]);

    Livewire::test(CreateSubscription::class)
        ->fillForm([
            'member_id' => $member->id,
            'add_on_id' => $this->entry->id,
            'covered_month' => '2026-07-15',
            'amount_paid' => 60,
            'paid_on' => '2026-07-01',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $subscription = Subscription::where('member_id', $member->id)->firstOrFail();
    expect($subscription->covered_month->toDateString())->toBe('2026-07-01')
        ->and($subscription->recorded_by)->toBe(auth()->id());
});

test('a subscription cannot be created for a member who is not subscription-eligible', function () {
    $member = Member::factory()->create(['subscription_eligible' => false]);

    Livewire::test(CreateSubscription::class)
        ->fillForm([
            'member_id' => $member->id,
            'add_on_id' => $this->entry->id,
            'covered_month' => '2026-07-15',
            'amount_paid' => 60,
            'paid_on' => '2026-07-01',
        ])
        ->call('create')
        ->assertHasFormErrors(['member_id']);

    expect(Subscription::where('member_id', $member->id)->exists())->toBeFalse();
});

test('the bulk-purchase action creates a correctly-split bundle', function () {
    Plan::create(['add_on_id' => $this->entry->id, 'duration_months' => 1, 'price' => 60, 'effective_from' => '2026-01-01']);
    Plan::create(['add_on_id' => $this->entry->id, 'duration_months' => 3, 'price' => 175, 'effective_from' => '2026-01-01']);
    $member = Member::factory()->create(['subscription_eligible' => true]);

    Livewire::test(ListSubscriptions::class)
        ->callAction('bulkPurchase', data: [
            'member_id' => $member->id,
            'add_on_id' => $this->entry->id,
            'desired_start' => '2026-07-01',
            'duration_months' => 3,
            'payment_method' => 'other',
            'paid_on' => '2026-07-01',
        ])
        ->assertHasNoActionErrors();

    $rows = Subscription::where('member_id', $member->id)->orderBy('covered_month')->get();

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('covered_month')->map->toDateString()->all())->toBe(['2026-07-01', '2026-08-01', '2026-09-01'])
        ->and((float) $rows->sum('amount_paid'))->toEqual(175.0);
});

test('the bulk-purchase action rejects a non-subscription-eligible member', function () {
    Plan::create(['add_on_id' => $this->entry->id, 'duration_months' => 1, 'price' => 60, 'effective_from' => '2026-01-01']);
    Plan::create(['add_on_id' => $this->entry->id, 'duration_months' => 3, 'price' => 175, 'effective_from' => '2026-01-01']);
    $member = Member::factory()->create(['subscription_eligible' => false]);

    Livewire::test(ListSubscriptions::class)
        ->callAction('bulkPurchase', data: [
            'member_id' => $member->id,
            'add_on_id' => $this->entry->id,
            'desired_start' => '2026-07-01',
            'duration_months' => 3,
        ])
        ->assertHasActionErrors(['member_id']);

    expect(Subscription::where('member_id', $member->id)->exists())->toBeFalse();
});

test('door cannot create subscriptions, so cannot reach the bulk-purchase action either', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);

    expect($door->can('create', Subscription::class))->toBeFalse()
        ->and($manager->can('create', Subscription::class))->toBeTrue();
});
