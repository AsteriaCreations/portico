<?php

use App\Models\Member;
use App\Models\MembershipSetting;
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

test('enabling name adds the "Last, First (username)" form', function () {
    MembershipSetting::current()->update(['member_search_fields' => ['username', 'name']]);

    expect(Member::pickerLabel(labelMember()))->toBe('Smith, John (jsmith99)');
});

test('enabling member number appends it after the username', function () {
    MembershipSetting::current()->update(['member_search_fields' => ['username', 'member_number']]);

    expect(Member::pickerLabel(labelMember()))->toBe('jsmith99 · #1042');
});

test('name without username drops the parenthetical', function () {
    MembershipSetting::current()->update(['member_search_fields' => ['name']]);

    expect(Member::pickerLabel(labelMember()))->toBe('Smith, John');
});

test('every enabled field shows, in a stable order', function () {
    MembershipSetting::current()->update([
        'member_search_fields' => ['username', 'name', 'preferred_name', 'member_number', 'email'],
    ]);

    expect(Member::pickerLabel(labelMember()))
        ->toBe('Smith, John (jsmith99) · "Johnny" · #1042 · john@example.test');
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
