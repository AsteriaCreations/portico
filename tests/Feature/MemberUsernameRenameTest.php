<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Members\Pages\EditMember;
use App\Models\Member;
use App\Models\MemberUsernameChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('a manager can rename a member\'s username via the header action, and it is logged', function () {
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);
    $member = Member::factory()->create(['username' => 'oldname']);

    Livewire::test(EditMember::class, ['record' => $member->getKey()])
        ->callAction('renameUsername', data: ['username' => 'newname'])
        ->assertHasNoActionErrors();

    $member->refresh();
    expect($member->username)->toBe('newname');

    $change = MemberUsernameChange::where('member_id', $member->id)->firstOrFail();
    expect($change->old_username)->toBe('oldname')
        ->and($change->new_username)->toBe('newname')
        ->and($change->changed_by)->toBe($manager->id);
});

test('renaming to a username already taken by another member is rejected', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));
    $member = Member::factory()->create(['username' => 'alice']);
    Member::factory()->create(['username' => 'bob']);

    Livewire::test(EditMember::class, ['record' => $member->getKey()])
        ->callAction('renameUsername', data: ['username' => 'bob'])
        ->assertHasActionErrors(['username']);

    expect($member->refresh()->username)->toBe('alice')
        ->and(MemberUsernameChange::where('member_id', $member->id)->exists())->toBeFalse();
});

test('the plain member edit form cannot change username directly -- only the rename action can', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));
    $member = Member::factory()->create(['username' => 'original']);

    Livewire::test(EditMember::class, ['record' => $member->getKey()])
        ->fillForm(['username' => 'hacked'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($member->refresh()->username)->toBe('original');
});

test('a console-driven username change with no authenticated user writes no audit row', function () {
    $member = Member::factory()->create(['username' => 'original']);

    $member->update(['username' => 'renamed']);

    expect(MemberUsernameChange::where('member_id', $member->id)->exists())->toBeFalse();
});

test('a member username change can never be updated or deleted, even by an admin', function () {
    $admin = User::factory()->create(['active' => true, 'role' => Role::Admin]);
    $change = MemberUsernameChange::factory()->create();

    expect($admin->can('update', $change))->toBeFalse()
        ->and($admin->can('delete', $change))->toBeFalse();
});

test('a door user cannot access the member edit page at all', function () {
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $member = Member::factory()->create();
    $this->actingAs($door);

    $this->get("/admin/members/{$member->id}/edit")->assertForbidden();
});
