<?php

use App\Enums\AddOnKind;
use App\Enums\Role;
use App\Filament\Admin\Pages\CheckIn;
use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Models\MiscellaneousPayment;
use App\Models\Plan;
use App\Models\Register;
use App\Models\RegisterShift;
use App\Models\Subscription;
use App\Models\User;
use App\Services\RegisterShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->irregular = Category::factory()->create(['name' => 'Irregular', 'is_comped' => false]);
    $this->prospective = Category::factory()->create(['name' => 'Prospective', 'is_comped' => false]);
    $this->guest = Category::factory()->create(['name' => 'Guest', 'is_comped' => false]);
    $this->user = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($this->user);

    $this->entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    $pool = AddOn::create(['name' => AddOn::POOL_NAME, 'subscribable' => true, 'priced_per_event' => true]);

    Plan::create(['add_on_id' => $this->entry->id, 'price' => 60, 'credit' => 25, 'effective_from' => '2026-01-01']);
    Plan::create(['add_on_id' => $pool->id, 'price' => 15, 'credit' => null, 'effective_from' => '2026-01-01']);

    $this->register = Register::factory()->create();
});

function trainingClearMember(Category $category, array $overrides = []): Member
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

test('the toggle turns training mode on and off and mirrors it into the session', function () {
    $component = Livewire::test(CheckIn::class);

    expect($component->instance()->trainingMode)->toBeFalse();

    $component->callAction('toggleTrainingMode');
    expect($component->instance()->trainingMode)->toBeTrue()
        ->and(session('checkin.training_mode'))->toBeTrue();

    $component->callAction('toggleTrainingMode');
    expect($component->instance()->trainingMode)->toBeFalse()
        ->and(session('checkin.training_mode'))->toBeFalse();
});

test('training mode is restored from the session on mount', function () {
    session(['checkin.training_mode' => true]);

    expect(Livewire::test(CheckIn::class)->instance()->trainingMode)->toBeTrue();
});

test('the banner renders only while training mode is on', function () {
    expect(Livewire::test(CheckIn::class)->html())
        ->not->toContain('Training mode — practice freely');

    expect(Livewire::test(CheckIn::class)->set('trainingMode', true)->html())
        ->toContain('Training mode — practice freely');
});

test('a check-in in training mode writes no attendance row', function () {
    $member = trainingClearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40, 'pool_fee' => 0]);

    Livewire::test(CheckIn::class)
        ->set('trainingMode', true)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: ['checked_in_at' => now()])
        ->assertHasNoActionErrors();

    expect(Attendance::count())->toBe(0);
});

test('with training mode off the same check-in still writes exactly one row', function () {
    $member = trainingClearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40, 'pool_fee' => 0]);

    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: ['checked_in_at' => now()])
        ->assertHasNoActionErrors();

    expect(Attendance::count())->toBe(1);
});

test('a standalone subscription purchase in training mode writes no subscription row', function () {
    $member = trainingClearMember($this->irregular, ['subscription_eligible' => true]);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40, 'pool_fee' => 0]);

    Livewire::test(CheckIn::class)
        ->set('trainingMode', true)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('purchaseSubscription', data: [
            'add_on_id' => $this->entry->id,
            'desired_start' => now()->startOfMonth()->toDateString(),
            'duration_months' => 1,
            'payment_method' => null,
        ])
        ->assertHasNoActionErrors();

    expect(Subscription::count())->toBe(0);
});

test('promoting a Prospective in training mode leaves the member untouched', function () {
    $member = trainingClearMember($this->prospective, ['email' => '']);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20]);

    Livewire::test(CheckIn::class)
        ->set('trainingMode', true)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('saveAndPromote', data: [
            'preferred_name' => 'Real',
            'first_name' => 'Real',
            'last_name' => 'Name',
            'email' => 'real@example.com',
        ])
        ->assertHasNoActionErrors();

    expect($member->fresh()->category_id)->toBe($this->prospective->id)
        ->and($member->fresh()->email)->toBe('');
});

test('registering a guest in training mode creates no new member', function () {
    $sponsor = trainingClearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20]);
    Attendance::factory()->for($sponsor)->for($event)->create(['checked_in_at' => now()]);

    $before = Member::count();

    Livewire::test(CheckIn::class)
        ->set('trainingMode', true)
        ->fillForm(['event_id' => $event->id, 'member_id' => $sponsor->id])
        ->callAction('registerGuest', data: [
            'username' => 'practice-guest',
            'preferred_name' => 'Practice',
            'first_name' => 'Practice',
            'last_name' => 'Guest',
            'email' => 'practice@example.com',
        ])
        ->assertHasNoActionErrors();

    expect(Member::count())->toBe($before)
        ->and(Member::where('username', 'practice-guest')->exists())->toBeFalse();
});

test('opening the register box in training mode starts no shift', function () {
    Livewire::test(CheckIn::class)
        ->set('trainingMode', true)
        ->set('registerId', $this->register->id)
        ->callAction('openShift', data: ['opening_count' => 100])
        ->assertHasNoActionErrors();

    expect(RegisterShift::count())->toBe(0);
});

test('recording an off-book payment in training mode writes nothing', function () {
    app(RegisterShiftService::class)->openShift($this->register, $this->user, 100);

    Livewire::test(CheckIn::class)
        ->set('trainingMode', true)
        ->set('registerId', $this->register->id)
        ->callAction('recordMiscPayment', data: [
            'payment_method' => 'cash',
            'amount' => 25,
            'notation' => 'practice donation',
        ])
        ->assertHasNoActionErrors();

    expect(MiscellaneousPayment::count())->toBe(0);
});

test('marking a prepaid patron arrived in training mode does not check them in', function () {
    $member = trainingClearMember($this->irregular);
    $event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 20]);
    $attendance = Attendance::factory()->for($member)->for($event)->create(['checked_in_at' => null]);

    Livewire::test(CheckIn::class)
        ->set('trainingMode', true)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('markArrived')
        ->assertHasNoActionErrors();

    expect($attendance->fresh()->checked_in_at)->toBeNull();
});

test('changing the register picker in training mode does not persist the user default', function () {
    Livewire::test(CheckIn::class)
        ->set('trainingMode', true)
        ->set('registerId', $this->register->id);

    expect($this->user->fresh()->default_register_id)->toBeNull();
});
