<?php

use App\Enums\Role;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function labelMember(): Member
{
    return Member::factory()->create([
        'username' => 'jsmith99',
        'first_name' => 'John',
        'last_name' => 'Smith',
        'preferred_name' => 'Johnny',
        'member_number' => 1042,
        'email' => 'john@example.test',
    ]);
}

test('username-only setting yields a username-only label — no name leaks', function () {
    $member = labelMember();

    expect(Member::pickerLabel($member))->toBe('jsmith99')
        ->not->toContain('Smith')
        ->not->toContain('John')
        ->not->toContain('1042')
        ->not->toContain('example.test');
});

test('more search fields don\'t lengthen the label: it shows the first enabled field the member has', function () {
    MembershipSetting::current()->update([
        'member_search_fields' => ['username', 'name', 'preferred_name', 'member_number', 'email'],
    ]);

    $member = labelMember();
    expect(Member::pickerLabel($member))->toBe('jsmith99');

    MembershipSetting::current()->update(['member_search_fields' => ['name', 'member_number']]);

    expect(Member::pickerLabel($member))->toBe('Smith, John');
});

test('a search result also shows the enabled field that matched, so staff can see why it came up', function () {
    MembershipSetting::current()->update([
        'member_search_fields' => ['username', 'name', 'preferred_name', 'member_number'],
    ]);
    $member = labelMember();

    expect(Member::pickerLabel($member, 'smi'))->toBe('jsmith99 · Smith, John')
        ->and(Member::pickerLabel($member, 'john'))->toBe('jsmith99 · Smith, John · "Johnny"')
        ->and(Member::pickerLabel($member, '104'))->toBe('jsmith99 · #1042')
        // Only the username matched: nothing extra.
        ->and(Member::pickerLabel($member, 'jsm'))->toBe('jsmith99');
});

test('a field the club hasn\'t enabled never shows, even when it would match', function () {
    MembershipSetting::current()->update(['member_search_fields' => ['username']]);

    expect(Member::pickerLabel(labelMember(), 'john'))->toBe('jsmith99');
});

test('email shows only to Manager and up, even when it is what matched', function () {
    MembershipSetting::current()->update(['member_search_fields' => ['username', 'email']]);
    $member = labelMember();

    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Door]));
    expect(Member::pickerLabel($member, 'john@'))->toBe('jsmith99');

    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));
    expect(Member::pickerLabel($member, 'john@'))->toBe('jsmith99 · john@example.test');
});

test('an email-only club shows Door the username, not the address', function () {
    MembershipSetting::current()->update(['member_search_fields' => ['email']]);

    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Door]));
    expect(Member::pickerLabel(labelMember(), 'john'))->toBe('jsmith99');
});

test('a member missing every enabled field falls back to the username, never a blank row', function () {
    MembershipSetting::current()->update(['member_search_fields' => ['name', 'email']]);

    $member = Member::factory()->create([
        'username' => 'ghost',
        'first_name' => null,
        'last_name' => null,
        'email' => null,
    ]);

    expect(Member::pickerLabel($member))->toBe('ghost');
});

test('name enabled but the member has no name falls back to username', function () {
    MembershipSetting::current()->update(['member_search_fields' => ['username', 'name']]);

    $member = Member::factory()->create(['username' => 'noname', 'first_name' => null, 'last_name' => null]);

    expect(Member::pickerLabel($member))->toBe('noname');
});
