<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\MembershipSettings;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the default is username-only', function () {
    expect(MembershipSetting::current()->member_search_fields)->toBe(['username'])
        ->and(Member::searchableColumns())->toBe(['username']);
});

test('searchableColumns expands the configured field keys to real DB columns', function () {
    MembershipSetting::current()->update([
        'member_search_fields' => ['username', 'name', 'member_number', 'preferred_name', 'email'],
    ]);

    expect(Member::searchableColumns())
        ->toBe(['username', 'first_name', 'last_name', 'member_number', 'preferred_name', 'email']);
});

test('searchableColumns falls back to username when the setting is empty or null', function () {
    MembershipSetting::current()->update(['member_search_fields' => null]);
    expect(Member::searchableColumns())->toBe(['username']);

    MembershipSetting::current()->update(['member_search_fields' => []]);
    expect(Member::searchableColumns())->toBe(['username']);
});

test('matchingSearch only matches enabled fields', function () {
    $member = Member::factory()->create([
        'username' => 'xkcd',
        'first_name' => 'Randall',
        'last_name' => 'Munroe',
        'preferred_name' => 'Randy',
        'member_number' => 8675309,
        'email' => 'randall@example.test',
    ]);

    // Username-only default: a name/number/email search finds nothing.
    expect(Member::matchingSearch('Munroe')->exists())->toBeFalse()
        ->and(Member::matchingSearch('8675309')->exists())->toBeFalse()
        ->and(Member::matchingSearch('randall@')->exists())->toBeFalse()
        ->and(Member::matchingSearch('xkcd')->first()?->id)->toBe($member->id);

    MembershipSetting::current()->update([
        'member_search_fields' => ['username', 'name', 'member_number', 'preferred_name', 'email'],
    ]);

    expect(Member::matchingSearch('Munroe')->first()?->id)->toBe($member->id)
        ->and(Member::matchingSearch('8675309')->first()?->id)->toBe($member->id)
        ->and(Member::matchingSearch('Randy')->first()?->id)->toBe($member->id)
        ->and(Member::matchingSearch('randall@')->first()?->id)->toBe($member->id);
});

test('searchableFieldsLabel reads as a human list', function () {
    MembershipSetting::current()->update(['member_search_fields' => ['username']]);
    expect(Member::searchableFieldsLabel())->toBe('username');

    MembershipSetting::current()->update(['member_search_fields' => ['username', 'name']]);
    expect(Member::searchableFieldsLabel())->toBe('username or name');

    MembershipSetting::current()->update(['member_search_fields' => ['username', 'name', 'member_number']]);
    expect(Member::searchableFieldsLabel())->toBe('username, name, or member number');
});

test('a manager can save the searchable member fields via the settings page', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(MembershipSettings::class)
        ->fillForm(['member_search_fields' => ['username', 'name', 'member_number']])
        ->callAction('save')
        ->assertHasNoActionErrors();

    expect(MembershipSetting::current()->member_search_fields)->toBe(['username', 'name', 'member_number']);
});

test('the settings page rejects an empty searchable-fields selection', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(MembershipSettings::class)
        ->fillForm(['member_search_fields' => []])
        ->callAction('save')
        ->assertHasActionErrors(['member_search_fields']);

    expect(MembershipSetting::current()->member_search_fields)->toBe(['username']);
});
