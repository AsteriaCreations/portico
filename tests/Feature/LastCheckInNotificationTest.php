<?php

use App\Enums\AddOnKind;
use App\Enums\Role;
use App\Filament\Admin\Pages\CheckIn;
use App\Models\AddOn;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Register;
use App\Models\User;
use App\Notifications\LastCheckInNotification;
use App\Services\RegisterShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->irregular = Category::factory()->create(['name' => 'Irregular', 'is_comped' => false]);
    $this->user = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($this->user);

    $entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    Plan::create(['add_on_id' => $entry->id, 'price' => 60, 'credit' => 25, 'effective_from' => '2026-01-01']);

    $this->register = Register::factory()->create();
    app(RegisterShiftService::class)->openShift($this->register, $this->user, 100);

    $this->event = Event::factory()->create(['name' => 'Friday Social', 'event_date' => now()->toDateString(), 'entry_fee' => 20, 'pool_fee' => 0]);
});

function lastCheckInMember(Category $category, string $username): Member
{
    return Member::factory()->create([
        'category_id' => $category->id,
        'username' => $username,
        'preferred_name' => null,
        'dob' => '1990-01-01',
        'is_banned' => false,
        'on_watchlist' => false,
        'first_name' => 'Pat',
        'last_name' => 'Doe',
        'email' => "{$username}@example.com",
    ]);
}

function checkInAtDesk(Register $register, Event $event, Member $member, array $data = []): void
{
    Livewire::test(CheckIn::class)
        ->set('registerId', $register->id)
        ->fillForm(['event_id' => $event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: array_merge(['checked_in_at' => now(), 'payment_method' => 'cash'], $data))
        ->assertHasNoActionErrors();
}

function lastCheckInEntries(User $user): Collection
{
    return DatabaseNotification::query()
        ->where('notifiable_id', $user->id)
        ->where('type', LastCheckInNotification::class)
        ->get();
}

test('a check-in leaves its amount due, payment method and member in the bell', function () {
    $member = lastCheckInMember($this->irregular, 'alpha');

    checkInAtDesk($this->register, $this->event, $member);

    $entries = lastCheckInEntries($this->user);
    expect($entries)->toHaveCount(1);

    $data = $entries->first()->data;
    expect($data['format'])->toBe('filament')
        ->and($data['title'])->toContain($member->displayName())
        ->and($data['body'])->toContain('$20.00 due')
        ->and($data['body'])->toContain('Cash')
        ->and($data['body'])->toContain('Friday Social');
});

test('each check-in replaces the previous one, keeping only the latest', function () {
    checkInAtDesk($this->register, $this->event, lastCheckInMember($this->irregular, 'alpha'));
    checkInAtDesk($this->register, $this->event, lastCheckInMember($this->irregular, 'bravo'));

    $entries = lastCheckInEntries($this->user);
    expect($entries)->toHaveCount(1)
        ->and($entries->first()->data['title'])->toContain('bravo');
});

test('it never touches another staff member\'s last check-in or other bell entries', function () {
    $otherDesk = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($otherDesk);
    checkInAtDesk($this->register, $this->event, lastCheckInMember($this->irregular, 'alpha'));

    $this->actingAs($this->user);
    $this->user->notifications()->create([
        'id' => (string) str()->uuid(),
        'type' => Filament\Notifications\DatabaseNotification::class,
        'data' => ['format' => 'filament', 'title' => 'Unrelated reminder'],
    ]);
    checkInAtDesk($this->register, $this->event, lastCheckInMember($this->irregular, 'bravo'));

    expect(lastCheckInEntries($otherDesk))->toHaveCount(1)
        ->and($this->user->notifications()->count())->toBe(2);
});

test('training mode leaves nothing in the bell', function () {
    $member = lastCheckInMember($this->irregular, 'alpha');

    Livewire::test(CheckIn::class)
        ->set('registerId', $this->register->id)
        ->set('trainingMode', true)
        ->fillForm(['event_id' => $this->event->id, 'member_id' => $member->id])
        ->callAction('checkIn', data: ['checked_in_at' => now(), 'payment_method' => 'cash']);

    expect(lastCheckInEntries($this->user))->toBeEmpty();
});
