<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Members\Pages\EditMember;
use App\Filament\Admin\Resources\Members\RelationManagers\BanExceptionsRelationManager;
use App\Models\BanException;
use App\Models\Event;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('a manager can grant a one-time ban exception for a specific event', function () {
    $manager = User::factory()->create(['role' => Role::Manager]);
    $this->actingAs($manager);
    $member = Member::factory()->create(['is_banned' => true]);
    $event = Event::factory()->create();

    Livewire::test(BanExceptionsRelationManager::class, [
        'ownerRecord' => $member,
        'pageClass' => EditMember::class,
    ])
        ->callTableAction('create', data: [
            'event_id' => $event->id,
            'reason' => 'Re-introduction newbie night',
        ])
        ->assertHasNoTableActionErrors();

    $exception = BanException::where('member_id', $member->id)->where('event_id', $event->id)->firstOrFail();
    expect($exception->reason)->toBe('Re-introduction newbie night')
        ->and($exception->granted_by)->toBe($manager->id);
});

test('a door volunteer has no access to the member resource, so cannot grant an exception', function () {
    $this->actingAs(User::factory()->create(['role' => Role::Door]));
    $member = Member::factory()->create(['is_banned' => true]);

    $this->get("/admin/members/{$member->id}/edit")->assertForbidden();
});

test('an exception granted for one event does not cover a different event', function () {
    $member = Member::factory()->create(['is_banned' => true]);
    $eventA = Event::factory()->create();
    $eventB = Event::factory()->create();
    BanException::factory()->create(['member_id' => $member->id, 'event_id' => $eventA->id]);

    expect($member->hasBanExceptionFor($eventA))->toBeTrue()
        ->and($member->hasBanExceptionFor($eventB))->toBeFalse();
});

test('a ban exception can never be updated or deleted, even by an admin', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $exception = BanException::factory()->create();

    expect($admin->can('update', $exception))->toBeFalse()
        ->and($admin->can('delete', $exception))->toBeFalse();
});
