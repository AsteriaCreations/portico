<?php

use App\Enums\AddOnKind;
use App\Enums\Role;
use App\Filament\Admin\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Models\AddOn;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
});

test('a manager sees and can use the grant-perk action', function () {
    $manager = User::factory()->create(['role' => Role::Manager]);
    $this->actingAs($manager);
    $beneficiary = Member::factory()->create(['subscription_eligible' => true]);

    Livewire::test(ListSubscriptions::class)
        ->assertActionVisible('grantManagerPerk')
        ->callAction('grantManagerPerk', data: ['member_id' => $beneficiary->id])
        ->assertHasNoActionErrors();

    $subscription = Subscription::where('member_id', $beneficiary->id)->firstOrFail();
    expect($subscription->amount_paid)->toEqual(0)
        ->and($subscription->recorded_by)->toBe($manager->id);
});

test('an admin does not see the grant-perk action', function () {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));

    Livewire::test(ListSubscriptions::class)
        ->assertActionHidden('grantManagerPerk');
});

test('an owner sees and can use the grant-perk action, despite outranking admin', function () {
    $owner = User::factory()->create(['role' => Role::Owner]);
    $this->actingAs($owner);
    $beneficiary = Member::factory()->create(['subscription_eligible' => true]);

    Livewire::test(ListSubscriptions::class)
        ->assertActionVisible('grantManagerPerk')
        ->callAction('grantManagerPerk', data: ['member_id' => $beneficiary->id])
        ->assertHasNoActionErrors();

    $subscription = Subscription::where('member_id', $beneficiary->id)->firstOrFail();
    expect($subscription->amount_paid)->toEqual(0)
        ->and($subscription->recorded_by)->toBe($owner->id);
});

test('a door volunteer has no access to the subscriptions page at all', function () {
    $this->actingAs(User::factory()->create(['role' => Role::Door]));

    $this->get('/admin/subscriptions')->assertForbidden();
});

test('the action disappears once a manager has used their perk this month', function () {
    $manager = User::factory()->create(['role' => Role::Manager]);
    $this->actingAs($manager);
    $beneficiary = Member::factory()->create(['subscription_eligible' => true]);

    Livewire::test(ListSubscriptions::class)
        ->callAction('grantManagerPerk', data: ['member_id' => $beneficiary->id])
        ->assertHasNoActionErrors();

    Livewire::test(ListSubscriptions::class)
        ->assertActionHidden('grantManagerPerk');
});

test('granting to a member already covered this month is rejected inline', function () {
    $manager = User::factory()->create(['role' => Role::Manager]);
    $this->actingAs($manager);
    $beneficiary = Member::factory()->create(['subscription_eligible' => true]);
    Subscription::create([
        'member_id' => $beneficiary->id,
        'add_on_id' => $this->entry->id,
        'covered_month' => now()->startOfMonth()->toDateString(),
        'amount_paid' => 60,
    ]);

    Livewire::test(ListSubscriptions::class)
        ->callAction('grantManagerPerk', data: ['member_id' => $beneficiary->id])
        ->assertHasActionErrors(['member_id']);

    expect(Subscription::where('member_id', $beneficiary->id)->count())->toBe(1);
});

test('granting to a member who is not yet subscription-eligible is rejected inline', function () {
    $manager = User::factory()->create(['role' => Role::Manager]);
    $this->actingAs($manager);
    $beneficiary = Member::factory()->create(['subscription_eligible' => false]);

    Livewire::test(ListSubscriptions::class)
        ->callAction('grantManagerPerk', data: ['member_id' => $beneficiary->id])
        ->assertHasActionErrors(['member_id']);

    expect(Subscription::where('member_id', $beneficiary->id)->exists())->toBeFalse();
});

test('disabling manager_perk_enabled hides the action even for a manager', function () {
    MembershipSetting::current()->update(['manager_perk_enabled' => false]);
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));

    Livewire::test(ListSubscriptions::class)
        ->assertActionHidden('grantManagerPerk');
});

test('the grant-manager-subscription-perk gate denies an admin, closing a pre-existing gap', function () {
    // grantManagerPerkAction()'s closure now does abort_unless(Gate::allows(
    // 'grant-manager-subscription-perk'), 403) as its first line -- this is
    // the exact boolean that check depends on. Filament's own test helpers
    // refuse to callAction() a hidden action (they assert visibility first,
    // a test-authoring nicety, not a security boundary), so the closure's
    // own defense-in-depth isn't directly triggerable through them -- same
    // as ActivePatrons::departAction()/CompRequestsRelationManager::
    // approveAction()'s own abort_unless() calls elsewhere in this app,
    // neither of which have a forced-call test either. Asserting the gate
    // itself is denied is what actually proves the fix.
    $admin = User::factory()->create(['role' => Role::Admin]);

    expect(Gate::forUser($admin)->allows('grant-manager-subscription-perk'))->toBeFalse();
});

test('the grant-manager-subscription-perk gate denies a manager once manager_perk_enabled is off', function () {
    MembershipSetting::current()->update(['manager_perk_enabled' => false]);
    $manager = User::factory()->create(['role' => Role::Manager]);

    expect(Gate::forUser($manager)->allows('grant-manager-subscription-perk'))->toBeFalse();

    MembershipSetting::current()->update(['manager_perk_enabled' => true]);

    expect(Gate::forUser($manager)->allows('grant-manager-subscription-perk'))->toBeTrue();
});
