<?php

use App\Enums\PlanType;
use App\Enums\PoolCoverageSource;
use App\Enums\Role;
use App\Filament\Admin\Pages\CheckIn;
use App\Filament\Admin\Resources\Events\Pages\EditEvent;
use App\Filament\Admin\Resources\Events\RelationManagers\PoolDayPassesRelationManager;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\Plan;
use App\Models\PoolDayPass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->irregular = Category::factory()->create(['name' => 'Irregular', 'is_comped' => false]);
    $this->user = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($this->user);

    Plan::create(['code' => PlanType::Regular, 'price' => 60, 'credit' => 25, 'effective_from' => '2026-01-01']);
    Plan::create(['code' => PlanType::Pool, 'price' => 15, 'credit' => null, 'effective_from' => '2026-01-01']);
});

function clearMemberForPoolDayPass(Category $category, array $overrides = []): Member
{
    return Member::factory()->create(array_merge([
        'category_id' => $category->id,
        'dob' => '1990-01-01',
        'is_banned' => false,
    ], $overrides));
}

test('the buy pool day pass action is visible once a member is selected, with no event picked and regardless of subscription eligibility', function () {
    $member = clearMemberForPoolDayPass($this->irregular, ['subscription_eligible' => false]);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $member->id])
        ->assertActionVisible('purchasePoolDayPass');
});

test('a member who is not subscription-eligible can still buy a pool day pass', function () {
    $member = clearMemberForPoolDayPass($this->irregular, ['subscription_eligible' => false]);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'pool_fee' => 15]);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $member->id])
        ->callAction('purchasePoolDayPass', data: [
            'event_id' => $event->id,
            'payment_method' => 'other',
        ])
        ->assertHasNoActionErrors();

    expect(PoolDayPass::where('member_id', $member->id)->where('event_id', $event->id)->exists())->toBeTrue();
});

test('a door volunteer can also buy a pool day pass standalone', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($door);

    $member = clearMemberForPoolDayPass($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'pool_fee' => 15]);

    Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $member->id])
        ->callAction('purchasePoolDayPass', data: [
            'event_id' => $event->id,
            'payment_method' => 'other',
        ])
        ->assertHasNoActionErrors();

    $pass = PoolDayPass::where('member_id', $member->id)->firstOrFail();
    expect($pass->amount_paid)->toEqual(15)
        ->and($pass->recorded_by)->toBe($door->id);
});

test('buying a second pool day pass for the same member and event is rejected without a server error', function () {
    $member = clearMemberForPoolDayPass($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'pool_fee' => 15]);

    $livewire = Livewire::test(CheckIn::class)->fillForm(['member_id' => $member->id]);

    $livewire->callAction('purchasePoolDayPass', data: ['event_id' => $event->id, 'payment_method' => 'other'])
        ->assertHasNoActionErrors();

    $livewire->callAction('purchasePoolDayPass', data: ['event_id' => $event->id, 'payment_method' => 'other'])
        ->assertHasNoActionErrors();

    expect(PoolDayPass::where('member_id', $member->id)->where('event_id', $event->id)->count())->toBe(1);
});

test('the event options only include current/future events with a pool fee', function () {
    $today = Event::factory()->create(['event_date' => today()->toDateString(), 'pool_fee' => 15]);
    $future = Event::factory()->create(['event_date' => today()->addWeek()->toDateString(), 'pool_fee' => 15]);
    $past = Event::factory()->create(['event_date' => today()->subWeek()->toDateString(), 'pool_fee' => 15]);
    $noPool = Event::factory()->create(['event_date' => today()->toDateString(), 'pool_fee' => 0]);

    $method = (new ReflectionClass(CheckIn::class))->getMethod('poolDayPassEventOptionsQuery');
    $method->setAccessible(true);
    $ids = $method->invoke(null)->pluck('id');

    expect($ids)->toContain($today->id)
        ->toContain($future->id)
        ->not->toContain($past->id)
        ->not->toContain($noPool->id);
});

test('the live running total reflects an already-purchased pool day pass before formal check-in', function () {
    $member = clearMemberForPoolDayPass($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40, 'pool_fee' => 15]);

    $component = Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $member->id])
        ->callAction('purchasePoolDayPass', data: ['event_id' => $event->id, 'payment_method' => 'other'])
        ->assertHasNoActionErrors();

    $component->fillForm(['event_id' => $event->id]);

    $breakdown = $component->instance()->getLivePriceBreakdown();

    expect($breakdown->poolCoveredBy)->toBe(PoolCoverageSource::DayPass)
        ->and($breakdown->poolCoverage)->toEqual(15.0);
});

test('checking in after buying a pool day pass records pool_covered_by as DayPass, not PoolSubscription, when both exist', function () {
    $member = clearMemberForPoolDayPass($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 0, 'pool_fee' => 15]);
    $member->subscriptions()->create(['plan_type' => PlanType::Pool, 'covered_month' => now()->startOfMonth(), 'amount_paid' => 15]);

    $component = Livewire::test(CheckIn::class)
        ->fillForm(['member_id' => $member->id])
        ->callAction('purchasePoolDayPass', data: ['event_id' => $event->id, 'payment_method' => 'other'])
        ->assertHasNoActionErrors();

    $component->fillForm(['event_id' => $event->id])
        ->callAction('checkIn', data: ['checked_in_at' => now()])
        ->assertHasNoActionErrors();

    $attendance = Attendance::where('member_id', $member->id)->where('event_id', $event->id)->firstOrFail();
    expect($attendance->pool_covered_by)->toBe(PoolCoverageSource::DayPass);
});

test('a Manager can see purchased pool day passes for an event via the read-only relation manager', function () {
    $event = Event::factory()->create(['pool_fee' => 15]);
    $member = clearMemberForPoolDayPass($this->irregular, ['username' => 'pass-holder']);
    $pass = PoolDayPass::factory()->create(['event_id' => $event->id, 'member_id' => $member->id, 'amount_paid' => 15, 'recorded_by' => $this->user->id]);

    Livewire::test(PoolDayPassesRelationManager::class, [
        'ownerRecord' => $event,
        'pageClass' => EditEvent::class,
    ])->assertCanSeeTableRecords([$pass]);
});

test('the pool day passes relation manager access follows pool_enabled', function () {
    $event = Event::factory()->create(['pool_fee' => 15]);
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);

    MembershipSetting::current()->update(['pool_enabled' => false]);
    expect($manager->can('viewAny', PoolDayPass::class))->toBeFalse();

    MembershipSetting::current()->update(['pool_enabled' => true]);
    expect($manager->can('viewAny', PoolDayPass::class))->toBeTrue();
});
