<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\RelationManagers\BehaviorNotesWrittenRelationManager;
use App\Models\AttendanceBehaviorNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('an admin sees behavior notes written by a specific staff member, not another staff member\'s notes', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($admin);

    $author = User::factory()->create(['role' => Role::Volunteer]);
    $otherAuthor = User::factory()->create(['role' => Role::Volunteer]);

    $ownNote = AttendanceBehaviorNote::factory()->create(['created_by' => $author->id, 'note' => 'Own note']);
    AttendanceBehaviorNote::factory()->create(['created_by' => $otherAuthor->id, 'note' => 'Someone else\'s note']);

    Livewire::test(BehaviorNotesWrittenRelationManager::class, [
        'ownerRecord' => $author,
        'pageClass' => EditUser::class,
    ])
        ->assertCanSeeTableRecords([$ownNote])
        ->assertCanNotSeeTableRecords(AttendanceBehaviorNote::where('created_by', $otherAuthor->id)->get());
});

test('a manager, below the Admin floor for the Users resource, cannot reach a staff member\'s written-notes tab', function () {
    $manager = User::factory()->create(['role' => Role::Manager]);
    $this->actingAs($manager);
    $target = User::factory()->create(['role' => Role::Volunteer]);

    $this->get("/admin/users/{$target->id}/edit")->assertForbidden();
});
