<?php

use App\Enums\MemberStatusField;
use App\Enums\Role;
use App\Filament\Admin\Resources\Members\Pages\EditMember;
use App\Filament\Admin\Resources\Rooms\Pages\CreateRoom;
use App\Filament\Admin\Resources\Rooms\Pages\ListRooms;
use App\Filament\Admin\Resources\Rooms\RoomResource;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\MemberStatusChange;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
});

test('private rentals are off by default and guests are allowed by default', function () {
    $settings = MembershipSetting::current();

    expect($settings->private_rentals_enabled)->toBeFalse()
        ->and($settings->private_rental_guests_allowed)->toBeTrue();
});

test('the Rooms list is hidden while private rentals are off', function () {
    $this->actingAs($this->manager);

    expect(RoomResource::canAccess())->toBeFalse();
    $this->get(RoomResource::getUrl())->assertForbidden();
});

test('a Manager can add a room once private rentals are on', function () {
    MembershipSetting::current()->update(['private_rentals_enabled' => true]);
    $this->actingAs($this->manager);

    $this->get(RoomResource::getUrl())->assertSuccessful();

    Livewire::test(CreateRoom::class)
        ->fillForm([
            'name' => 'Blue Room',
            'hourly_rate' => 40,
            'included_people' => 12,
            'overage_fee' => 5,
            'overage_per_hour' => false,
            'min_hours' => 2,
            'max_hours' => 6,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $room = Room::sole();
    expect($room->name)->toBe('Blue Room')
        ->and($room->hourly_rate)->toBe('40.00')
        ->and($room->included_people)->toBe(12)
        ->and($room->overage_per_hour)->toBeFalse();
});

test('a room cannot allow fewer maximum hours than its minimum', function () {
    MembershipSetting::current()->update(['private_rentals_enabled' => true]);
    $this->actingAs($this->manager);

    Livewire::test(CreateRoom::class)
        ->fillForm(['name' => 'Red Room', 'hourly_rate' => 40, 'min_hours' => 4, 'max_hours' => 2])
        ->call('create')
        ->assertHasFormErrors(['max_hours']);
});

test('Door cannot reach the Rooms list even with private rentals on', function () {
    MembershipSetting::current()->update(['private_rentals_enabled' => true]);
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Door]));

    $this->get(RoomResource::getUrl())->assertForbidden();
});

test('rooms show on the list with their prices', function () {
    MembershipSetting::current()->update(['private_rentals_enabled' => true]);
    $this->actingAs($this->manager);
    $room = Room::factory()->create(['name' => 'Green Room']);

    Livewire::test(ListRooms::class)->assertCanSeeTableRecords([$room]);
});

test('blocking a member from private rentals is logged with its reason', function () {
    MembershipSetting::current()->update(['private_rentals_enabled' => true]);
    $this->actingAs($this->manager);
    $member = Member::factory()->create();

    Livewire::test(EditMember::class, ['record' => $member->getRouteKey()])
        ->fillForm(['rentals_blocked' => true, 'rentals_blocked_reason' => 'Left the room damaged'])
        ->call('save')
        ->assertHasNoFormErrors();

    $change = MemberStatusChange::sole();
    expect($member->refresh()->rentals_blocked)->toBeTrue()
        ->and($change->status)->toBe(MemberStatusField::RentalsBlocked)
        ->and($change->value)->toBeTrue()
        ->and($change->reason)->toBe('Left the room damaged')
        ->and($change->changed_by)->toBe($this->manager->id);
});

test('blocking a member from private rentals needs a reason', function () {
    MembershipSetting::current()->update(['private_rentals_enabled' => true]);
    $this->actingAs($this->manager);
    $member = Member::factory()->create();

    Livewire::test(EditMember::class, ['record' => $member->getRouteKey()])
        ->fillForm(['rentals_blocked' => true, 'rentals_blocked_reason' => null])
        ->call('save')
        ->assertHasFormErrors(['rentals_blocked_reason']);
});

test('the No private rentals toggle is hidden while the feature is off, unless already set', function () {
    $this->actingAs($this->manager);
    $clear = Member::factory()->create();
    $blocked = Member::factory()->create(['rentals_blocked' => true, 'rentals_blocked_reason' => 'Earlier']);

    Livewire::test(EditMember::class, ['record' => $clear->getRouteKey()])
        ->assertFormFieldHidden('rentals_blocked');

    Livewire::test(EditMember::class, ['record' => $blocked->getRouteKey()])
        ->assertFormFieldVisible('rentals_blocked');
});
