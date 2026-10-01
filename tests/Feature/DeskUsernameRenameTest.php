<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\CheckIn;
use App\Filament\Admin\Pages\FeatureFlags;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\MemberUsernameChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function deskRenameUser(Role $role): User
{
    return User::factory()->create(['active' => true, 'role' => $role]);
}

test('door_username_rename_enabled is off by default', function () {
    expect(MembershipSetting::current()->door_username_rename_enabled)->toBeFalse();
});

test('door cannot rename a username at the desk while the setting is off', function () {
    $this->actingAs(deskRenameUser(Role::Door));
    $member = Member::factory()->create(['username' => 'oldname']);

    expect(Gate::allows('rename-member-username'))->toBeFalse();

    Livewire::test(CheckIn::class)
        ->set('data.member_id', $member->id)
        ->assertActionHidden('renameUsername');

    expect($member->refresh()->username)->toBe('oldname');
});

test('door can rename a username at the desk once the setting is on, and it is logged', function () {
    MembershipSetting::current()->update(['door_username_rename_enabled' => true]);
    $door = deskRenameUser(Role::Door);
    $this->actingAs($door);
    $member = Member::factory()->create(['username' => 'oldname']);

    Livewire::test(CheckIn::class)
        ->set('data.member_id', $member->id)
        ->assertActionVisible('renameUsername')
        ->callAction('renameUsername', data: ['username' => 'newname'])
        ->assertHasNoActionErrors();

    expect($member->refresh()->username)->toBe('newname');

    $change = MemberUsernameChange::where('member_id', $member->id)->firstOrFail();
    expect($change->old_username)->toBe('oldname')
        ->and($change->new_username)->toBe('newname')
        ->and($change->changed_by)->toBe($door->id);
});

test('the desk rename rejects a username already taken by another member', function () {
    MembershipSetting::current()->update(['door_username_rename_enabled' => true]);
    $this->actingAs(deskRenameUser(Role::Door));
    $member = Member::factory()->create(['username' => 'alice']);
    Member::factory()->create(['username' => 'bob']);

    Livewire::test(CheckIn::class)
        ->set('data.member_id', $member->id)
        ->callAction('renameUsername', data: ['username' => 'bob'])
        ->assertHasActionErrors(['username']);

    expect($member->refresh()->username)->toBe('alice')
        ->and(MemberUsernameChange::where('member_id', $member->id)->exists())->toBeFalse();
});

test('the desk rename saves nothing in training mode', function () {
    MembershipSetting::current()->update(['door_username_rename_enabled' => true]);
    $this->actingAs(deskRenameUser(Role::Door));
    $member = Member::factory()->create(['username' => 'oldname']);

    Livewire::test(CheckIn::class)
        ->set('trainingMode', true)
        ->set('data.member_id', $member->id)
        ->callAction('renameUsername', data: ['username' => 'newname']);

    expect($member->refresh()->username)->toBe('oldname')
        ->and(MemberUsernameChange::where('member_id', $member->id)->exists())->toBeFalse();
});

test('the desk rename is hidden until a member is selected', function () {
    MembershipSetting::current()->update(['door_username_rename_enabled' => true]);
    $this->actingAs(deskRenameUser(Role::Door));

    Livewire::test(CheckIn::class)->assertActionHidden('renameUsername');
});

test('a manager can rename at the desk regardless of the setting', function () {
    $this->actingAs(deskRenameUser(Role::Manager));
    $member = Member::factory()->create(['username' => 'oldname']);

    Livewire::test(CheckIn::class)
        ->set('data.member_id', $member->id)
        ->callAction('renameUsername', data: ['username' => 'newname'])
        ->assertHasNoActionErrors();

    expect($member->refresh()->username)->toBe('newname');
});

test('the setting never extends renaming below door', function () {
    MembershipSetting::current()->update(['door_username_rename_enabled' => true]);

    foreach ([Role::Showrunner, Role::Volunteer, Role::DM] as $role) {
        expect(Gate::forUser(deskRenameUser($role))->allows('rename-member-username'))->toBeFalse();
    }
});

test('a manager can turn the setting on from the feature flags page', function () {
    $this->actingAs(deskRenameUser(Role::Manager));

    Livewire::test(FeatureFlags::class)
        ->fillForm(['door_username_rename_enabled' => true])
        ->callAction('save');

    expect(MembershipSetting::current()->door_username_rename_enabled)->toBeTrue();
});
