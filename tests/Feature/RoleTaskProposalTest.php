<?php

use App\Enums\Role;
use App\Enums\RoleTaskStatus;
use App\Filament\Admin\Pages\MyTasks;
use App\Filament\Admin\Pages\TaskProposals;
use App\Models\MembershipSetting;
use App\Models\RoleTask;
use App\Models\RoleTaskVote;
use App\Models\ShiftRole;
use App\Models\TaskArea;
use App\Models\User;
use App\Services\RoleTaskProposalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    MembershipSetting::current()->update(['staff_scheduling_enabled' => true]);

    $this->desk = ShiftRole::factory()->create(['name' => 'Desk']);
    $this->cleaning = ShiftRole::factory()->create(['name' => 'Cleaning Crew', 'scheduled' => false]);
    $this->manager = User::factory()->create(['name' => 'Manager', 'role' => Role::Manager, 'active' => true]);

    $person = fn (string $name, array $roles): User => tap(User::factory()->create(['name' => $name, 'role' => Role::Door, 'active' => true]), fn (User $user) => $user->shiftRoles()->attach($roles));
    $this->sam = $person('Sam', [$this->desk->id]);
    $this->jo = $person('Jo', [$this->desk->id]);
    $this->lee = $person('Lee', [$this->cleaning->id]);

    $this->service = app(RoleTaskProposalService::class);
});

test('staff propose a task for their role from My tasks, and Manager+ is notified', function () {
    $this->actingAs($this->sam);

    Livewire::test(MyTasks::class)
        ->callAction('propose', ['shift_role_id' => $this->desk->id, 'name' => 'Wipe the card reader'])
        ->assertHasNoActionErrors();

    expect(RoleTask::sole())
        ->status->toBe(RoleTaskStatus::Proposed)
        ->proposed_by->toBe($this->sam->id)
        ->and($this->manager->notifications()->sole()->data['title'])->toBe('New task proposed for Desk')
        ->and($this->sam->notifications()->count())->toBe(0);
});

test('you can only propose for a role you work', function () {
    expect(fn () => $this->service->propose($this->lee, $this->desk, 'Count the float', null, null))->toThrow(ValidationException::class)
        ->and($this->service->rolesFor($this->lee)->pluck('name')->all())->toBe(['Cleaning Crew']);
});

test('others with the role vote up or down, once each, and can change or take back their vote', function () {
    $task = $this->service->propose($this->sam, $this->desk, 'Wipe the card reader', null, null);

    $this->actingAs($this->jo);
    $page = Livewire::test(MyTasks::class)
        ->assertSee('Wipe the card reader')
        ->call('vote', $task->id, 1);
    expect($this->service->tally($task->fresh()))->toBe(['up' => 1, 'down' => 0]);

    $page->call('vote', $task->id, -1);
    expect($this->service->tally($task->fresh()))->toBe(['up' => 0, 'down' => 1]);

    // Pressing the same vote again takes it back.
    $page->call('vote', $task->id, -1);
    expect(RoleTaskVote::count())->toBe(0);
});

test('you can\'t vote on your own proposal, or for a role you don\'t work, or once it\'s decided', function () {
    $task = $this->service->propose($this->sam, $this->desk, 'Wipe the card reader', null, null);

    expect(fn () => $this->service->vote($this->sam, $task, 1))->toThrow(ValidationException::class, 'your own')
        ->and(fn () => $this->service->vote($this->lee, $task, 1))->toThrow(ValidationException::class);

    $this->actingAs($this->lee);
    Livewire::test(MyTasks::class)->assertDontSee('Wipe the card reader');

    $this->service->decline($this->manager, $task, 'Already in the closing list');
    expect(fn () => $this->service->vote($this->jo, $task->fresh(), 1))->toThrow(ValidationException::class, 'already been decided');
});

test('a Manager approves a proposal onto the standard list, and the proposer is told', function () {
    $task = $this->service->propose($this->sam, $this->desk, 'Wipe the card reader', null, null);
    $this->service->vote($this->jo, $task, 1);
    $area = TaskArea::factory()->create(['name' => 'Front desk']);

    $this->actingAs($this->manager);
    expect(TaskProposals::getNavigationBadge())->toBe('1');

    Livewire::test(TaskProposals::class)
        ->assertCanSeeTableRecords([$task])
        ->callTableAction('approve', $task, ['task_area_id' => $area->id, 'sort_order' => 5])
        ->assertHasNoTableActionErrors();

    expect($task->fresh())
        ->status->toBe(RoleTaskStatus::Standard)
        ->task_area_id->toBe($area->id)
        ->sort_order->toBe(5)
        ->decided_by->toBe($this->manager->id)
        ->and($this->sam->notifications()->sole()->data['title'])->toBe('Your task "Wipe the card reader" was added to the Desk list')
        ->and(TaskProposals::getNavigationBadge())->toBeNull();
});

test('declining needs a reason, which the proposer is told; it stays off the list', function () {
    $task = $this->service->propose($this->sam, $this->desk, 'Wipe the card reader', null, null);

    $this->actingAs($this->manager);
    Livewire::test(TaskProposals::class)
        ->callTableAction('decline', $task, ['reason' => ''])
        ->assertHasTableActionErrors(['reason' => 'required']);

    Livewire::test(TaskProposals::class)
        ->callTableAction('decline', $task, ['reason' => 'Already on the closing list'])
        ->assertHasNoTableActionErrors();

    expect($task->fresh())->status->toBe(RoleTaskStatus::Declined)->decline_reason->toBe('Already on the closing list')
        ->and($this->sam->notifications()->sole()->data['body'])->toBe('Already on the closing list')
        ->and(RoleTask::standard()->count())->toBe(0);
});

test('only Manager+ reaches the proposals queue or decides one', function () {
    $task = $this->service->propose($this->sam, $this->desk, 'Wipe the card reader', null, null);

    $this->actingAs($this->jo);
    expect(TaskProposals::canAccess())->toBeFalse()
        ->and(fn () => $this->service->approve($this->jo, $task, null, 0))->toThrow(ValidationException::class);
});
