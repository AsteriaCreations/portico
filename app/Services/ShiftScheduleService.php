<?php

namespace App\Services;

use App\Enums\Role;
use App\Filament\Admin\Pages\MyShifts;
use App\Models\MembershipSetting;
use App\Models\ScheduleWeek;
use App\Models\ShiftAssignment;
use App\Models\ShiftSlot;
use App\Models\ShiftWeekNote;
use App\Models\User;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as LaravelNotification;
use Illuminate\Validation\ValidationException;

/**
 * Building and publishing the staff schedule (Admin+, the Staff schedule
 * page). A shift only takes people who can work its role, no more than it
 * needs, and nobody on two shifts that overlap. Nothing is shown to staff
 * until the week is published; publishing bells everyone scheduled with
 * their shifts, and after that each change bells the person it affects.
 */
class ShiftScheduleService
{
    /** Order the picker lists people in. */
    private const PREFERENCE_RANK = ['preferred' => 0, 'available' => 1, 'none' => 2, 'unavailable' => 3];

    /** @var array<int, Collection<int, User>> */
    private array $eligibleByRole = [];

    public function weekStart(CarbonInterface $date): CarbonInterface
    {
        return MembershipSetting::startOfScheduleWeek($date)->startOfDay();
    }

    public function publication(CarbonInterface $weekStart): ?ScheduleWeek
    {
        return ScheduleWeek::query()->whereDate('week_starts_on', $this->weekStart($weekStart)->toDateString())->first();
    }

    public function isPublished(CarbonInterface $weekStart): bool
    {
        return $this->publication($weekStart) !== null;
    }

    /**
     * Every shift in the week on unarchived events, earliest first, with what
     * the picker needs loaded.
     *
     * @return Collection<int, ShiftSlot>
     */
    public function slotsFor(CarbonInterface $weekStart): Collection
    {
        $weekStart = $this->weekStart($weekStart);

        return ShiftSlot::query()
            ->with(['event', 'shiftRole.users' => fn ($query) => $query->where('active', true)->orderBy('name'), 'assignments.user', 'availabilities'])
            ->whereHas('event', fn (Builder $query) => $query->whereNull('archived_at'))
            ->where('starts_at', '>=', $weekStart)
            ->where('starts_at', '<', $weekStart->copy()->addWeek())
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Who can be put on $slot, best answer first, as id => "Name (answer)".
     * Anyone already on it stays listed even if they've since lost the role,
     * so saving doesn't silently drop them.
     *
     * @return array<int, string>
     */
    public function candidateOptions(ShiftSlot $slot): array
    {
        $answers = $slot->availabilities->mapWithKeys(fn ($answer): array => [$answer->user_id => $answer->preference]);
        $people = $this->eligibleFor($slot)->concat($slot->assignments->pluck('user'))->filter()->unique('id');

        return $people
            ->sortBy(fn (User $user): string => self::PREFERENCE_RANK[$answers->get($user->id)?->value ?? 'none'].mb_strtolower($user->name))
            ->mapWithKeys(fn (User $user): array => [$user->id => __(':name (:answer)', [
                'name' => $user->name,
                'answer' => $answers->get($user->id)?->getLabel() ?? __('no answer'),
            ])])
            ->all();
    }

    /**
     * The staff week notes for the week, by person.
     *
     * @return Collection<int, ShiftWeekNote>
     */
    public function notesFor(CarbonInterface $weekStart): Collection
    {
        return ShiftWeekNote::query()
            ->with('user')
            ->whereDate('week_starts_on', $this->weekStart($weekStart)->toDateString())
            ->get()
            ->sortBy('user.name')
            ->values();
    }

    /**
     * Who can be put on $slot: its role's list, or the whole roster for a
     * role open to everyone. Held per role for the request, since a week
     * repeats the same roles.
     *
     * @return Collection<int, User>
     */
    public function eligibleFor(ShiftSlot $slot): Collection
    {
        return $this->eligibleByRole[$slot->shift_role_id] ??= $slot->shiftRole->eligibleUsers();
    }

    public function isLocked(ShiftSlot $slot): bool
    {
        return $slot->starts_at->isPast();
    }

    /**
     * Sets who is on each shift of the week. $people is keyed by shift slot
     * id; a slot left out, or one that has started, is unchanged. Refuses the
     * whole save (a ValidationException keyed "slot_{id}") if a shift gets
     * someone who can't work its role, too many people, or someone already
     * on an overlapping shift. Returns how many assignments were added or
     * removed.
     *
     * @param  array<int|string, list<int|string>>  $people
     */
    public function assign(User $by, CarbonInterface $weekStart, array $people): int
    {
        abort_unless($by->can('build-staff-schedule'), 403);

        $weekStart = $this->weekStart($weekStart);
        $slots = $this->slotsFor($weekStart)->keyBy('id');
        $wanted = [];

        foreach ($people as $slotId => $userIds) {
            $slot = $slots->get((int) $slotId);

            if ($slot === null || $this->isLocked($slot)) {
                continue;
            }

            $userIds = collect($userIds)->map(fn ($id): int => (int) $id)->unique()->values()->all();
            $current = $slot->assignments->pluck('user_id')->all();
            $allowed = [...$this->eligibleFor($slot)->modelKeys(), ...$current];

            if (array_diff($userIds, $allowed) !== []) {
                throw ValidationException::withMessages(['slot_'.$slot->id => __('Only people who can work :role can be scheduled on it.', ['role' => $slot->shiftRole->name])]);
            }

            if (count($userIds) > $slot->headcount) {
                throw ValidationException::withMessages(['slot_'.$slot->id => trans_choice('This shift needs :count person.|This shift needs :count people.', $slot->headcount)]);
            }

            $wanted[$slot->id] = $userIds;
        }

        $this->refuseOverlaps($slots, $wanted);

        $added = [];
        $removed = [];

        DB::transaction(function () use ($by, $slots, $wanted, &$added, &$removed): void {
            foreach ($wanted as $slotId => $userIds) {
                $slot = $slots->get($slotId);
                $current = $slot->assignments->pluck('user_id')->all();

                foreach (array_diff($current, $userIds) as $userId) {
                    ShiftAssignment::query()->where('shift_slot_id', $slotId)->where('user_id', $userId)->delete();
                    $removed[] = [$slot, $userId];
                }

                foreach (array_diff($userIds, $current) as $userId) {
                    ShiftAssignment::create(['shift_slot_id' => $slotId, 'user_id' => $userId, 'assigned_by' => $by->id]);
                    $added[] = [$slot, $userId];
                }
            }
        });

        if ($this->isPublished($weekStart)) {
            $this->notifyChanges($added, $removed);
        }

        return count($added) + count($removed);
    }

    /**
     * Publishes the week to staff and bells everyone on it with their
     * shifts. Publishing an already published week does nothing.
     */
    public function publish(User $by, CarbonInterface $weekStart): ScheduleWeek
    {
        abort_unless($by->can('build-staff-schedule'), 403);

        $weekStart = $this->weekStart($weekStart);

        if ($existing = $this->publication($weekStart)) {
            return $existing;
        }

        $week = ScheduleWeek::create(['week_starts_on' => $weekStart->toDateString(), 'published_at' => now(), 'published_by' => $by->id]);

        $this->slotsFor($weekStart)
            ->flatMap(fn (ShiftSlot $slot) => $slot->assignments->map(fn (ShiftAssignment $assignment): array => [$assignment->user, $slot]))
            ->groupBy(fn (array $pair): int => $pair[0]->id)
            ->each(function ($pairs) use ($weekStart): void {
                $lines = $pairs->map(fn (array $pair): string => $this->describe($pair[1]))->implode("\n");

                LaravelNotification::sendNow($pairs->first()[0], Notification::make()
                    ->title(__('Your shifts for the week of :date', ['date' => $weekStart->isoFormat('ddd ll')]))
                    ->body($lines)
                    ->icon('heroicon-o-calendar-days')
                    ->info()
                    ->actions([$this->viewScheduleAction($weekStart)])
                    ->toDatabase());
            });

        return $week;
    }

    public function canViewSchedule(User $user): bool
    {
        return $user->active
            && MembershipSetting::current()->staff_scheduling_enabled
            && ($user->role->atLeast(Role::Admin)
                || $user->shiftRoles()->scheduled()->exists()
                || $user->shiftAssignments()->exists());
    }

    /**
     * What staff see on My shifts: the week's shifts once it's published,
     * nothing while it's still a draft.
     *
     * @return Collection<int, ShiftSlot>
     */
    public function publishedSlotsFor(CarbonInterface $weekStart): Collection
    {
        return $this->isPublished($weekStart) ? $this->slotsFor($weekStart) : new Collection;
    }

    private function viewScheduleAction(CarbonInterface $weekStart): Action
    {
        return Action::make('viewSchedule')
            ->label(__('View schedule'))
            ->url(MyShifts::getUrl(['week' => $this->weekStart($weekStart)->toDateString()]))
            ->markAsRead();
    }

    public function describe(ShiftSlot $slot): string
    {
        return __(':shift, :event: :day :start to :end', [
            'shift' => $slot->displayName(),
            'event' => $slot->event->name,
            'day' => $slot->starts_at->isoFormat('ddd ll'),
            'start' => $slot->starts_at->isoFormat('LT'),
            'end' => $slot->ends_at->isoFormat('LT'),
        ]);
    }

    /**
     * Nobody on two shifts whose times overlap: among the shifts being saved,
     * and against their other shifts (another week's, or ones left out).
     *
     * @param  \Illuminate\Support\Collection<int, ShiftSlot>  $slots
     * @param  array<int, list<int>>  $wanted
     */
    private function refuseOverlaps($slots, array $wanted): void
    {
        $byUser = [];

        foreach ($wanted as $slotId => $userIds) {
            foreach ($userIds as $userId) {
                $byUser[$userId][] = $slots->get($slotId);
            }
        }

        foreach ($byUser as $userId => $userSlots) {
            $others = ShiftSlot::query()
                ->with('shiftRole')
                ->whereHas('assignments', fn (Builder $query) => $query->where('user_id', $userId))
                ->whereNotIn('id', array_keys($wanted))
                ->get();

            $all = collect($userSlots)->concat($others)->sortBy('starts_at')->values();

            for ($i = 1; $i < $all->count(); $i++) {
                $previous = $all[$i - 1];
                $slot = $all[$i];

                if ($slot->starts_at->lt($previous->ends_at)) {
                    $clash = isset($wanted[$slot->id]) ? $slot : $previous;
                    $other = $clash->is($slot) ? $previous : $slot;

                    throw ValidationException::withMessages(['slot_'.$clash->id => __(':name is already on :shift at that time.', [
                        'name' => User::find($userId)?->name,
                        'shift' => $other->displayName().' '.$other->starts_at->isoFormat('ddd LT'),
                    ])]);
                }
            }
        }
    }

    /**
     * @param  list<array{0: ShiftSlot, 1: int}>  $added
     * @param  list<array{0: ShiftSlot, 1: int}>  $removed
     */
    private function notifyChanges(array $added, array $removed): void
    {
        foreach ([[$added, __('You\'ve been added to a shift')], [$removed, __('You\'ve been taken off a shift')]] as [$changes, $title]) {
            foreach ($changes as [$slot, $userId]) {
                if ($user = User::find($userId)) {
                    LaravelNotification::sendNow($user, Notification::make()
                        ->title($title)
                        ->body($this->describe($slot))
                        ->icon('heroicon-o-calendar-days')
                        ->warning()
                        ->actions([$this->viewScheduleAction($slot->starts_at)])
                        ->toDatabase());
                }
            }
        }
    }
}
