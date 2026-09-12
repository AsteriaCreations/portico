<?php

use App\Models\Member;
use App\Models\MembershipSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function displayNameMember(array $overrides = []): Member
{
    return Member::factory()->create(array_merge([
        'username' => 'jsmith99',
        'first_name' => 'John',
        'last_name' => 'Smith',
        'preferred_name' => 'Johnny',
    ], $overrides));
}

test('defaults to preferred_name with no setting saved', function () {
    expect(displayNameMember()->displayName())->toBe('Johnny');
});

test('preferred_name setting shows the preferred name', function () {
    MembershipSetting::current()->update(['checkin_display_name_field' => 'preferred_name']);

    expect(displayNameMember()->displayName())->toBe('Johnny');
});

test('full_name setting shows "First Last"', function () {
    MembershipSetting::current()->update(['checkin_display_name_field' => 'full_name']);

    expect(displayNameMember()->displayName())->toBe('John Smith');
});

test('username setting shows the username', function () {
    MembershipSetting::current()->update(['checkin_display_name_field' => 'username']);

    expect(displayNameMember()->displayName())->toBe('jsmith99');
});

test('a blank preferred_name falls back to username', function () {
    MembershipSetting::current()->update(['checkin_display_name_field' => 'preferred_name']);

    expect(displayNameMember(['preferred_name' => null])->displayName())->toBe('jsmith99');
});

test('a blank full_name falls back to username', function () {
    MembershipSetting::current()->update(['checkin_display_name_field' => 'full_name']);

    expect(displayNameMember(['first_name' => null, 'last_name' => null])->displayName())->toBe('jsmith99');
});
