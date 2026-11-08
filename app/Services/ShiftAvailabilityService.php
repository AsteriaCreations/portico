<?php

namespace App\Services;

use App\Enums\ShiftPreference;
use App\Models\MembershipSetting;
use App\Models\ScheduleWeek;
use App\Models\ShiftAvailability;
use App\Models\ShiftSlot;
use App\Models\ShiftWeekNote;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Staff saying which shifts they can work (My availability). A person is
 * only offered the shifts of roles they can work (shift_role_user), on
 * events that aren't archived, in one schedule week at a time. A shift that
 * has already started can't be changed: by then the answer is history.
 */
class ShiftAvailabilityService
{
    public function canGiveAvailability(User $user): bool
    {
        return $user->active
            && MembershipSetting::current()->staff_scheduling_enabled
            && $user->shiftRoles()->where('active', true)->exists();
    }

    /**
     * The shifts $user can answer for in the schedule week starting $weekStart,
     * earliest first, with their event and role loaded.
     *
     * @return Collection<int, ShiftSlot>
     */
    public function slotsFor(User $user, CarbonInterface $weekStart): Collection
    {
        $weekStart = MembershipSetting::startOfScheduleWeek($weekStart)->startOfDay();

        return ShiftSlot::query()
            ->with(['event', 'shiftRole'])
            ->whereIn('shift_role_id', $user->shiftRoles()->where('active', true)->select('shift_roles.id'))
            ->whereHas('event', fn (Builder $query) => $query->whereNull('archived_at'))
            ->where('starts_at', '>=', $weekStart)
            ->where('starts_at', '<', $weekStart->copy()->addWeek())
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * $user's answers for those shifts, keyed by shift slot id.
     *
     * @param  Collection<int, ShiftSlot>  $slots
     * @return array<int, ShiftPreference>
     */
    public function answersFor(User $user, Collection $slots): array
    {
        return ShiftAvailability::query()
            ->where('user_id', $user->id)
            ->whereIn('shift_slot_id', $slots->modelKeys())
            ->get()
            ->mapWithKeys(fn (ShiftAvailability $answer): array => [$answer->shift_slot_id => $answer->preference])
            ->all();
    }

    public function noteFor(User $user, CarbonInterface $weekStart): ?string
    {
        return ShiftWeekNote::query()
            ->where('user_id', $user->id)
            ->whereDate('week_starts_on', MembershipSetting::startOfScheduleWeek($weekStart)->toDateString())
            ->value('note');
    }

    /**
     * Started, or its week has been published: from then on the schedule,
     * not availability, is what counts.
     */
    public function isLocked(ShiftSlot $slot): bool
    {
        return $slot->starts_at->isPast()
            || ScheduleWeek::query()->whereDate('week_starts_on', MembershipSetting::startOfScheduleWeek($slot->starts_at)->toDateString())->exists();
    }

    /**
     * Saves $user's answers for the week. $answers is keyed by shift slot id;
     * null clears an answer. A slot outside what slotsFor() offers (another
     * role's, another week's, a forged id) or one already started is left
     * alone. Returns how many answers changed.
     *
     * @param  array<int|string, ShiftPreference|string|null>  $answers
     */
    public function save(User $user, CarbonInterface $weekStart, array $answers, ?string $note): int
    {
        abort_unless($this->canGiveAvailability($user), 403);

        $weekStart = MembershipSetting::startOfScheduleWeek($weekStart)->startOfDay();
        $slots = $this->slotsFor($user, $weekStart)->keyBy('id');
        $changed = 0;

        DB::transaction(function () use ($user, $weekStart, $answers, $note, $slots, &$changed): void {
            foreach ($answers as $slotId => $value) {
                $slot = $slots->get((int) $slotId);

                if ($slot === null || $this->isLocked($slot)) {
                    continue;
                }

                $preference = $value instanceof ShiftPreference ? $value : ShiftPreference::tryFrom((string) $value);
                $existing = ShiftAvailability::query()->where('user_id', $user->id)->where('shift_slot_id', $slot->id)->first();

                if ($preference === null) {
                    $changed += (int) ($existing?->delete() ?? 0);

                    continue;
                }

                if ($existing?->preference === $preference) {
                    continue;
                }

                ShiftAvailability::query()->updateOrCreate(
                    ['user_id' => $user->id, 'shift_slot_id' => $slot->id],
                    ['preference' => $preference],
                );
                $changed++;
            }

            $note = filled($note) ? trim($note) : null;
            $existingNote = ShiftWeekNote::query()->where('user_id', $user->id)->whereDate('week_starts_on', $weekStart->toDateString());

            if ($note === null) {
                $existingNote->delete();
            } elseif ($existingNote->clone()->exists()) {
                // Not "update() returned 0": MySQL counts an unchanged row as 0 affected.
                $existingNote->update(['note' => $note]);
            } else {
                ShiftWeekNote::create(['user_id' => $user->id, 'week_starts_on' => $weekStart->toDateString(), 'note' => $note]);
            }
        });

        return $changed;
    }
}
