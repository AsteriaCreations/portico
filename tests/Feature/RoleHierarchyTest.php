<?php

use App\Enums\Role;

test('door only meets door', function () {
    expect(Role::Door->atLeast(Role::Door))->toBeTrue()
        ->and(Role::Door->atLeast(Role::Manager))->toBeFalse()
        ->and(Role::Door->atLeast(Role::Admin))->toBeFalse();
});

test('volunteer meets showrunner and volunteer but not dm or door', function () {
    expect(Role::Volunteer->atLeast(Role::Showrunner))->toBeTrue()
        ->and(Role::Volunteer->atLeast(Role::Volunteer))->toBeTrue()
        ->and(Role::Volunteer->atLeast(Role::DM))->toBeFalse()
        ->and(Role::Volunteer->atLeast(Role::Door))->toBeFalse()
        ->and(Role::Volunteer->atLeast(Role::Manager))->toBeFalse();
});

test('dm meets showrunner and volunteer as well as dm, but not door', function () {
    expect(Role::DM->atLeast(Role::Showrunner))->toBeTrue()
        ->and(Role::DM->atLeast(Role::Volunteer))->toBeTrue()
        ->and(Role::DM->atLeast(Role::DM))->toBeTrue()
        ->and(Role::DM->atLeast(Role::Door))->toBeFalse()
        ->and(Role::DM->atLeast(Role::Manager))->toBeFalse();
});

test('door meets showrunner, volunteer, and dm as well as door', function () {
    expect(Role::Door->atLeast(Role::Showrunner))->toBeTrue()
        ->and(Role::Door->atLeast(Role::Volunteer))->toBeTrue()
        ->and(Role::Door->atLeast(Role::DM))->toBeTrue();
});

test('manager meets dm, door, and manager but not admin', function () {
    expect(Role::Manager->atLeast(Role::DM))->toBeTrue()
        ->and(Role::Manager->atLeast(Role::Door))->toBeTrue()
        ->and(Role::Manager->atLeast(Role::Manager))->toBeTrue()
        ->and(Role::Manager->atLeast(Role::Admin))->toBeFalse();
});

test('admin meets every level below owner, but not owner', function () {
    expect(Role::Admin->atLeast(Role::DM))->toBeTrue()
        ->and(Role::Admin->atLeast(Role::Door))->toBeTrue()
        ->and(Role::Admin->atLeast(Role::Manager))->toBeTrue()
        ->and(Role::Admin->atLeast(Role::Admin))->toBeTrue()
        ->and(Role::Admin->atLeast(Role::Owner))->toBeFalse();
});

test('owner meets every level, including admin', function () {
    expect(Role::Owner->atLeast(Role::DM))->toBeTrue()
        ->and(Role::Owner->atLeast(Role::Door))->toBeTrue()
        ->and(Role::Owner->atLeast(Role::Manager))->toBeTrue()
        ->and(Role::Owner->atLeast(Role::Admin))->toBeTrue()
        ->and(Role::Owner->atLeast(Role::Owner))->toBeTrue();
});
