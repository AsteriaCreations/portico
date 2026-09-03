<?php

use App\Enums\Capability;
use App\Enums\Role;
use App\Filament\Admin\Pages\CleaningChecklist;
use App\Models\CleaningTask;
use App\Models\CleaningTaskCompletion;
use App\Models\User;
use App\Models\UserCapability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function crewUser(): User
{
    $user = User::factory()->create(['active' => true, 'role' => Role::Showrunner]);
    UserCapability::factory()->create(['user_id' => $user->id, 'capability' => Capability::CleaningCrew]);

    return $user;
}

test('a Cleaning Crew capability holder can access the page, but a Manager without the capability cannot', function () {
    $crew = crewUser();
    $this->actingAs($crew);
    expect(CleaningChecklist::canAccess())->toBeTrue();
    $this->get('/admin/cleaning-checklist')->assertSuccessful();

    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);
    expect(CleaningChecklist::canAccess())->toBeFalse();
    $this->get('/admin/cleaning-checklist')->assertForbidden();
});

test('a Door user with no capability gets 403 hitting the page directly', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Door]));

    $this->get('/admin/cleaning-checklist')->assertForbidden();
});

test('completing a task records a completion for the acting user and this week', function () {
    $crew = crewUser();
    $this->actingAs($crew);
    $task = CleaningTask::factory()->create(['active' => true]);

    Livewire::test(CleaningChecklist::class)
        ->callTableAction('complete', $task)
        ->assertHasNoTableActionErrors();

    $completion = CleaningTaskCompletion::where('cleaning_task_id', $task->id)->firstOrFail();
    expect($completion->completed_by)->toBe($crew->id)
        ->and($completion->for_week_start->toDateString())->toBe(now()->startOfWeek()->toDateString());
});

test('two different capability holders can each complete a different task in the same week', function () {
    $crewA = crewUser();
    $crewB = crewUser();
    $taskA = CleaningTask::factory()->create(['active' => true]);
    $taskB = CleaningTask::factory()->create(['active' => true]);

    $this->actingAs($crewA);
    Livewire::test(CleaningChecklist::class)->callTableAction('complete', $taskA)->assertHasNoTableActionErrors();

    $this->actingAs($crewB);
    Livewire::test(CleaningChecklist::class)->callTableAction('complete', $taskB)->assertHasNoTableActionErrors();

    expect(CleaningTaskCompletion::where('cleaning_task_id', $taskA->id)->firstOrFail()->completed_by)->toBe($crewA->id)
        ->and(CleaningTaskCompletion::where('cleaning_task_id', $taskB->id)->firstOrFail()->completed_by)->toBe($crewB->id);
});

test('completing the same task twice in the same week does not duplicate', function () {
    $crew = crewUser();
    $this->actingAs($crew);
    $task = CleaningTask::factory()->create(['active' => true]);

    Livewire::test(CleaningChecklist::class)->callTableAction('complete', $task)->assertHasNoTableActionErrors();
    Livewire::test(CleaningChecklist::class)->callTableAction('complete', $task)->assertHasNoTableActionErrors();

    expect(CleaningTaskCompletion::where('cleaning_task_id', $task->id)->count())->toBe(1);
});

test('an inactive task never appears on the checklist', function () {
    $crew = crewUser();
    $this->actingAs($crew);
    $active = CleaningTask::factory()->create(['active' => true]);
    $inactive = CleaningTask::factory()->create(['active' => false]);

    Livewire::test(CleaningChecklist::class)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$inactive]);
});

test('a completion from last week does not count as completed this week', function () {
    $crew = crewUser();
    $task = CleaningTask::factory()->create(['active' => true]);
    CleaningTaskCompletion::factory()->create([
        'cleaning_task_id' => $task->id,
        'for_week_start' => now()->subWeek()->startOfWeek()->toDateString(),
    ]);

    expect($task->isCompletedForWeek(now()->startOfWeek()))->toBeFalse();
});
