<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\FeatureFlags;
use App\Filament\Admin\Resources\Plans\PlanResource;
use App\Filament\Admin\Resources\Registers\RegisterResource;
use App\Models\AddOn;
use App\Models\MembershipSetting;
use App\Models\PaperworkType;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($this->manager);
    $this->pool = AddOn::create(['name' => AddOn::POOL_NAME, 'subscribable' => true, 'priced_per_event' => true]);
});

function reminderTitles(User $user): array
{
    return $user->fresh()->notifications->map(fn ($notification) => $notification->data['title'])->sort()->values()->all();
}

function saveFlags(array $changes): void
{
    Livewire::test(FeatureFlags::class)
        ->fillForm($changes)
        ->callAction('save')
        ->assertHasNoActionErrors();
}

test('turning Pool on sends a reminder for each Pool setup step that is still undone', function () {
    MembershipSetting::current()->update(['pool_enabled' => false]);

    saveFlags(['pool_enabled' => true]);

    // No upcoming events exist, so the pool-fee reminder has nothing to point at.
    expect(reminderTitles($this->manager))->toBe([
        'Pool: check the Pool Waiver',
        'Pool: set the Pool subscription price',
    ]);
});

test('a Pool step that is already done sends no reminder', function () {
    MembershipSetting::current()->update(['pool_enabled' => false]);
    Plan::create(['add_on_id' => $this->pool->id, 'price' => 15, 'effective_from' => '2026-01-01']);
    PaperworkType::where('name', 'Pool Waiver')->update(['gates_add_on_id' => $this->pool->id, 'active' => true]);

    saveFlags(['pool_enabled' => true]);

    expect(reminderTitles($this->manager))->toBe([]);
});

test('saving with a flag already on, or turning one off, sends nothing', function () {
    MembershipSetting::current()->update(['pool_enabled' => true, 'vouchers_enabled' => true]);

    saveFlags(['pool_enabled' => true, 'vouchers_enabled' => false]);

    expect(reminderTitles($this->manager))->toBe([]);
});

test('a reminder opens the screen where the step is done', function () {
    MembershipSetting::current()->update(['pool_enabled' => false]);

    saveFlags(['pool_enabled' => true]);

    $notification = $this->manager->fresh()->notifications
        ->first(fn ($notification) => $notification->data['title'] === 'Pool: set the Pool subscription price');

    expect($notification->data['actions'][0]['url'])->toBe(PlanResource::getUrl('index'));
});

test('turning register shifts on links to Registers, which only opens once the flag is saved', function () {
    MembershipSetting::current()->update(['register_shifts_enabled' => false]);

    saveFlags(['register_shifts_enabled' => true]);

    $notification = $this->manager->fresh()->notifications
        ->first(fn ($notification) => $notification->data['title'] === 'Register shifts: add your cash drawers');

    expect($notification)->not->toBeNull()
        ->and($notification->data['actions'][0]['url'])->toBe(RegisterResource::getUrl('index'));
});

test('reminders go only to the person who turned the feature on', function () {
    $otherAdmin = User::factory()->create(['active' => true, 'role' => Role::Admin]);
    MembershipSetting::current()->update(['pool_enabled' => false]);

    saveFlags(['pool_enabled' => true]);

    expect(reminderTitles($this->manager))->not->toBe([])
        ->and(reminderTitles($otherAdmin))->toBe([]);
});
