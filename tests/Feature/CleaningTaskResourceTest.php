<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\CleaningTasks\Pages\CreateCleaningTask;
use App\Filament\Admin\Resources\CleaningTasks\Pages\EditCleaningTask;
use App\Filament\Admin\Resources\CleaningTasks\Pages\ListCleaningTasks;
use App\Models\CleaningTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('cleaning tasks list page renders for a manager', function () {
    CleaningTask::factory()->count(2)->create();
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(ListCleaningTasks::class)->assertSuccessful();
});

test('a manager can create a cleaning task', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));

    Livewire::test(CreateCleaningTask::class)
        ->fillForm([
            'name' => 'Wipe down mats',
            'sort_order' => 10,
            'active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(CleaningTask::where('name', 'Wipe down mats')->exists())->toBeTrue();
});

test('a manager can edit and delete a cleaning task', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));
    $task = CleaningTask::factory()->create(['name' => 'Original name']);

    Livewire::test(EditCleaningTask::class, ['record' => $task->getKey()])
        ->fillForm(['name' => 'Renamed task'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($task->refresh()->name)->toBe('Renamed task');

    Livewire::test(EditCleaningTask::class, ['record' => $task->getKey()])
        ->callAction('delete');

    expect(CleaningTask::find($task->id))->toBeNull();
});

test('a door user has no access to the cleaning tasks resource', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Door]));

    $this->get('/admin/cleaning-tasks')->assertForbidden();
});
