<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\CheckIn;
use App\Filament\Admin\Pages\FeatureFlags;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function deskPreferredNameUser(Role $role): User
{
    return User::factory()->create(['active' => true, 'role' => $role]);
}

test('door_preferred_name_change_enabled is off by default', function () {
    expect(MembershipSetting::current()->door_preferred_name_change_enabled)->toBeFalse();
});

test('door neither sees the button nor passes the gate while the setting is off', function () {
    $this->actingAs(deskPreferredNameUser(Role::Door));
    $member = Member::factory()->create(['preferred_name' => 'Sam']);

    expect(Gate::allows('change-member-preferred-name'))->toBeFalse();

    Livewire::test(CheckIn::class)
        ->set('data.member_id', $member->id)
        ->assertActionHidden('changePreferredName');

    expect($member->refresh()->preferred_name)->toBe('Sam');
});

test('door can change the selected member\'s preferred name once the setting is on', function () {
    MembershipSetting::current()->update(['door_preferred_name_change_enabled' => true]);
    $this->actingAs(deskPreferredNameUser(Role::Door));
    $member = Member::factory()->create(['preferred_name' => 'Sam']);

    Livewire::test(CheckIn::class)
        ->set('data.member_id', $member->id)
        ->assertActionVisible('changePreferredName')
        ->mountAction('changePreferredName')
        ->assertActionDataSet(['preferred_name' => 'Sam'])
        ->setActionData(['preferred_name' => '  Samantha '])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($member->refresh()->preferred_name)->toBe('Samantha');
});

test('a manager sees the button with the setting off, and a blank name clears it', function () {
    $this->actingAs(deskPreferredNameUser(Role::Manager));
    $member = Member::factory()->create(['preferred_name' => 'Sam']);

    Livewire::test(CheckIn::class)
        ->set('data.member_id', $member->id)
        ->assertActionVisible('changePreferredName')
        ->callAction('changePreferredName', data: ['preferred_name' => ''])
        ->assertHasNoActionErrors();

    expect($member->refresh()->preferred_name)->toBeNull();
});

test('roles below door never pass the gate, even with the setting on', function () {
    MembershipSetting::current()->update(['door_preferred_name_change_enabled' => true]);

    foreach ([Role::Showrunner, Role::Volunteer, Role::DM] as $role) {
        $this->actingAs(deskPreferredNameUser($role));
        expect(Gate::allows('change-member-preferred-name'))->toBeFalse();
    }
});

test('training mode saves nothing', function () {
    MembershipSetting::current()->update(['door_preferred_name_change_enabled' => true]);
    $this->actingAs(deskPreferredNameUser(Role::Door));
    $member = Member::factory()->create(['preferred_name' => 'Sam']);

    Livewire::test(CheckIn::class)
        ->set('trainingMode', true)
        ->set('data.member_id', $member->id)
        ->callAction('changePreferredName', data: ['preferred_name' => 'Samantha']);

    expect($member->refresh()->preferred_name)->toBe('Sam');
});

test('the Feature Flags page saves the setting', function () {
    $this->actingAs(deskPreferredNameUser(Role::Admin));

    Livewire::test(FeatureFlags::class)
        ->fillForm(['door_preferred_name_change_enabled' => true])
        ->callAction('save');

    expect(MembershipSetting::current()->door_preferred_name_change_enabled)->toBeTrue();
});
