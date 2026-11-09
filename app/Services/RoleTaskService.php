<?php

namespace App\Services;

use App\Enums\RoleTaskStatus;
use App\Models\MembershipSetting;
use App\Models\RoleTask;
use App\Models\RoleTaskCompletion;
use App\Models\ShiftRole;
use App\Models\ShiftSlot;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Working a shift role's task list. A scheduled role's tasks are ticked
 * once per shift, on a shift of that role that's running (from
 * EARLY_MINUTES before it starts until LATE_MINUTES after it ends, for
 * closing tasks). Anyone who can work the role can tick; someone who isn't
 * on that shift is recorded as off shift, which shows as extra effort. An
 * unscheduled role's tasks (Cleaning) are ticked once per schedule week by
 * anyone with the role. A tick can be undone by the person who made it
 * while its shift or week is still open.
 */
class RoleTaskService
{
    public const EARLY_MINUTES = 60;

    public const LATE_MINUTES = 60;

    public function canUseTasks(User $user): bool
    {
        return $user->active
            && MembershipSetting::current()->staff_scheduling_enabled
            && $user->shiftRoles()->where('active', true)->exists();
    }

    /**
     * Whether $user can work $role: on its list, or for a role anyone can
     * work (Setup), anyone with a scheduled role.
     */
    public function holdsRole(User $user, ShiftRole $role): bool
    {
        if (! $role->active) {
            return false;
        }

        return $role->open_to_all && $role->scheduled
            ? $user->shiftRoles()->scheduled()->exists()
            : $user->shiftRoles()->whereKey($role->id)->exists();
    }

    /**
     * The shifts running now whose tasks $user can tick: shifts they're on,
     * and other shifts of roles they can work. Only roles with tasks.
     *
     * @return Collection<int, ShiftSlot>
     */
    public function currentShiftsFor(User $user): Collection
    {
        $now = now();

        return ShiftSlot::query()
            ->with(['event', 'shiftRole', 'assignments'])
            ->whereHas('shiftRole', fn (Builder $role) => $role->scheduled()->whereHas('tasks', fn (Builder $task) => $task->standard()))
            ->whereHas('event', fn (Builder $event) => $event->whereNull('archived_at'))
            ->where('starts_at', '<=', $now->copy()->addMinutes(self::EARLY_MINUTES))
            ->where('ends_at', '>=', $now->copy()->subMinutes(self::LATE_MINUTES))
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (ShiftSlot $slot): bool => $this->isOnShift($user, $slot) || $this->holdsRole($user, $slot->shiftRole))
            ->sortByDesc(fn (ShiftSlot $slot): bool => $this->isOnShift($user, $slot))
            ->values();
    }

    /**
     * $user's roles that aren't scheduled and have tasks: worked weekly.
     *
     * @return Collection<int, ShiftRole>
     */
    public function weeklyRolesFor(User $user): Collection
    {
        return $user->shiftRoles()
            ->where('active', true)
            ->where('scheduled', false)
            ->whereHas('tasks', fn (Builder $task) => $task->standard())
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * A role's standard tasks, by area then order; tasks with no area last.
     *
     * @return Collection<int, RoleTask>
     */
    public function tasksFor(ShiftRole $role): Collection
    {
        return $role->tasks()
            ->standard()
            ->with('area')
            ->get()
            ->sortBy(fn (RoleTask $task): string => ($task->area === null ? '1' : '0'.sprintf('%06d', $task->area->sort_order).$task->area->name).'|'.sprintf('%06d', $task->sort_order).$task->name)
            ->values();
    }

    /**
     * What's been ticked on $slot (a scheduled role) or in this schedule
     * week (an unscheduled one), keyed by task id.
     *
     * @return Collection<int, RoleTaskCompletion>
     */
    public function completionsFor(ShiftRole $role, ?ShiftSlot $slot): Collection
    {
        return RoleTaskCompletion::query()
            ->with('user')
            ->whereHas('task', fn (Builder $task) => $task->where('shift_role_id', $role->id))
            ->when(
                $role->scheduled,
                fn (Builder $query) => $query->where('shift_slot_id', $slot?->id),
                fn (Builder $query) => $query->whereDate('week_start', $this->weekStart()),
            )
            ->get()
            ->keyBy('role_task_id');
    }

    public function complete(User $user, RoleTask $task, ?ShiftSlot $slot): RoleTaskCompletion
    {
        $role = $task->shiftRole;
        $refuse = fn (string $message) => throw ValidationException::withMessages(['task' => $message]);

        if (! $this->canUseTasks($user) || ! $task->active || $task->status !== RoleTaskStatus::Standard || ! $role->active) {
            $refuse(__('That task isn\'t on a list you can work.'));
        }

        if ($role->scheduled) {
            if ($slot === null || $slot->shift_role_id !== $role->id || ! $this->isShiftOpen($slot)) {
                $refuse(__('That shift isn\'t running now, so its tasks can\'t be ticked.'));
            }

            $onShift = $this->isOnShift($user, $slot);

            if (! $onShift && ! $this->holdsRole($user, $role)) {
                $refuse(__('Only people who can work :role can tick its tasks.', ['role' => $role->name]));
            }

            $attributes = ['shift_slot_id' => $slot->id, 'week_start' => null, 'on_shift' => $onShift];
        } else {
            if (! $this->holdsRole($user, $role)) {
                $refuse(__('Only people who can work :role can tick its tasks.', ['role' => $role->name]));
            }

            $attributes = ['shift_slot_id' => null, 'week_start' => $this->weekStart()->toDateString(), 'on_shift' => null];
        }

        $done = $this->completionsFor($role, $slot)->get($task->id);

        if ($done !== null) {
            $refuse(__(':name already did this one.', ['name' => $done->user?->name]));
        }

        try {
            return RoleTaskCompletion::create(['role_task_id' => $task->id, 'user_id' => $user->id, 'completed_at' => now(), ...$attributes]);
        } catch (UniqueConstraintViolationException) {
            $refuse(__('Someone else just did this one.'));
        }
    }

    /**
     * Takes back $user's own tick while its shift or week is still open.
     */
    public function undo(User $user, RoleTaskCompletion $completion): void
    {
        $open = $completion->shift_slot_id !== null
            ? $completion->shiftSlot !== null && $this->isShiftOpen($completion->shiftSlot)
            : $completion->week_start?->isSameDay($this->weekStart());

        if ($completion->user_id !== $user->id || ! $open) {
            throw ValidationException::withMessages(['task' => __('Only your own tick can be undone, while its shift or week is still on.')]);
        }

        $completion->delete();
    }

    public function isShiftOpen(ShiftSlot $slot): bool
    {
        $now = now();

        return $slot->starts_at->lte($now->copy()->addMinutes(self::EARLY_MINUTES))
            && $slot->ends_at->gte($now->copy()->subMinutes(self::LATE_MINUTES));
    }

    public function isOnShift(User $user, ShiftSlot $slot): bool
    {
        return $slot->assignments->contains('user_id', $user->id);
    }

    public function weekStart(): CarbonInterface
    {
        return MembershipSetting::startOfScheduleWeek(now())->startOfDay();
    }
}
