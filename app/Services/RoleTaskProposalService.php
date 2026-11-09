<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\RoleTaskStatus;
use App\Filament\Admin\Pages\MyTasks;
use App\Filament\Admin\Pages\TaskProposals;
use App\Models\RoleTask;
use App\Models\RoleTaskVote;
use App\Models\ShiftRole;
use App\Models\TaskArea;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification as LaravelNotification;
use Illuminate\Validation\ValidationException;

/**
 * Staff growing their roles' task lists. Anyone who can work a role
 * proposes a task for it; the others who can work it vote it up or down
 * (not on their own); a Manager+ sees the votes and approves it onto the
 * standard list or declines it with a reason. The votes are advice: only
 * the Manager's decision changes the list. Manager+ is told of each new
 * proposal, and the proposer of the decision.
 */
class RoleTaskProposalService
{
    public function __construct(private RoleTaskService $tasks) {}

    /**
     * The active roles $user can propose tasks for.
     *
     * @return Collection<int, ShiftRole>
     */
    public function rolesFor(User $user): Collection
    {
        return ShiftRole::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->filter(fn (ShiftRole $role): bool => $this->tasks->holdsRole($user, $role))
            ->values();
    }

    public function propose(User $user, ShiftRole $role, string $name, ?string $description, ?TaskArea $area): RoleTask
    {
        if (! $this->tasks->canUseTasks($user) || ! $this->tasks->holdsRole($user, $role)) {
            throw ValidationException::withMessages(['shift_role_id' => __('Only people who can work :role can propose tasks for it.', ['role' => $role->name])]);
        }

        $task = RoleTask::create([
            'shift_role_id' => $role->id,
            'task_area_id' => $area?->active ? $area->id : null,
            'name' => $name,
            'description' => $description,
            'status' => RoleTaskStatus::Proposed,
            'proposed_by' => $user->id,
            'sort_order' => 0,
            'active' => true,
        ]);

        $managers = User::query()
            ->where('active', true)
            ->whereIn('role', [Role::Manager, Role::Admin, Role::Owner])
            ->whereKeyNot($user->id)
            ->get();

        LaravelNotification::sendNow($managers, Notification::make()
            ->title(__('New task proposed for :role', ['role' => $role->name]))
            ->body(__(':name proposed ":task".', ['name' => $user->name, 'task' => $name]))
            ->icon('heroicon-o-light-bulb')
            ->info()
            ->actions([Action::make('review')->label(__('Review'))->url(TaskProposals::getUrl())->markAsRead()])
            ->toDatabase());

        return $task;
    }

    /**
     * Proposals for roles $user can work, oldest first.
     *
     * @return Collection<int, RoleTask>
     */
    public function openFor(User $user): Collection
    {
        $roleIds = $this->rolesFor($user)->pluck('id');

        return RoleTask::query()
            ->with(['shiftRole', 'area', 'proposedBy', 'votes'])
            ->where('status', RoleTaskStatus::Proposed)
            ->whereIn('shift_role_id', $roleIds)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * $vote is 1 (up), -1 (down) or 0 (take the vote back).
     */
    public function vote(User $user, RoleTask $task, int $vote): void
    {
        $refuse = fn (string $message) => throw ValidationException::withMessages(['vote' => $message]);

        if ($task->status !== RoleTaskStatus::Proposed) {
            $refuse(__('That proposal has already been decided.'));
        }

        if ($task->proposed_by === $user->id) {
            $refuse(__('You can\'t vote on your own proposal.'));
        }

        if (! $this->tasks->canUseTasks($user) || ! $this->tasks->holdsRole($user, $task->shiftRole)) {
            $refuse(__('Only people who can work :role can vote on its tasks.', ['role' => $task->shiftRole->name]));
        }

        if ($vote === 0) {
            RoleTaskVote::where('role_task_id', $task->id)->where('user_id', $user->id)->delete();

            return;
        }

        RoleTaskVote::updateOrCreate(['role_task_id' => $task->id, 'user_id' => $user->id], ['vote' => $vote > 0 ? 1 : -1]);
    }

    /**
     * @return array{up: int, down: int}
     */
    public function tally(RoleTask $task): array
    {
        $votes = $task->relationLoaded('votes') ? $task->votes : $task->votes()->get();

        return ['up' => $votes->where('vote', 1)->count(), 'down' => $votes->where('vote', -1)->count()];
    }

    public function approve(User $manager, RoleTask $task, ?TaskArea $area, int $sortOrder): void
    {
        $this->decide($manager, $task, RoleTaskStatus::Standard, [
            'task_area_id' => $area?->id,
            'sort_order' => $sortOrder,
        ], __('Your task ":task" was added to the :role list', ['task' => $task->name, 'role' => $task->shiftRole->name]), null);
    }

    public function decline(User $manager, RoleTask $task, string $reason): void
    {
        $this->decide($manager, $task, RoleTaskStatus::Declined, [
            'decline_reason' => $reason,
        ], __('Your task ":task" wasn\'t added to the :role list', ['task' => $task->name, 'role' => $task->shiftRole->name]), $reason);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function decide(User $manager, RoleTask $task, RoleTaskStatus $status, array $attributes, string $title, ?string $body): void
    {
        if (! $manager->role->atLeast(Role::Manager) || ! $manager->active) {
            throw ValidationException::withMessages(['task' => __('Only a Manager or above can decide proposals.')]);
        }

        if ($task->status !== RoleTaskStatus::Proposed) {
            throw ValidationException::withMessages(['task' => __('That proposal has already been decided.')]);
        }

        $task->update([...$attributes, 'status' => $status, 'decided_by' => $manager->id, 'decided_at' => now()]);

        if ($task->proposedBy !== null && $task->proposed_by !== $manager->id) {
            LaravelNotification::sendNow($task->proposedBy, Notification::make()
                ->title($title)
                ->body($body)
                ->icon($status === RoleTaskStatus::Standard ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle')
                ->color($status === RoleTaskStatus::Standard ? 'success' : 'warning')
                ->actions([Action::make('view')->label(__('My tasks'))->url(MyTasks::getUrl())->markAsRead()])
                ->toDatabase());
        }
    }
}
