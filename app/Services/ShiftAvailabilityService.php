<?php

namespace App\Services;

use App\Enums\ShiftPreference;
use App\Enums\ShiftPresence;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\ScheduleWeek;
use App\Models\ShiftAvailability;
use App\Models\ShiftPresenceAnswer;
use App\Models\ShiftSlot;
use App\Models\ShiftWeekNote;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Staff saying which shifts they can work (My availability). A person is
 * only offered the shifts of roles they can work (shift_role_user, or any
 * role open to everyone, like Setup), on
 * events that aren't archived, in one schedule week at a time. A shift that
 * has already started can't be changed: by then the answer is history.
 * Manager+ can answer for someone else (enter-staff-availability), for
 * staff who don't use the app; each answer records who entered it.
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
     * Everyone availability can be entered for: active logins with an active
     * shift role, by name.
     *
     * @return Collection<int, User>
     */
    public function roster(): Collection
    {
        return User::query()
            ->where('active', true)
            ->whereHas('shiftRoles', fn (Builder $query) => $query->where('active', true))
            ->orderBy('name')
            ->get();
    }

    /**
     * For each answer of $user's that someone else entered, who did, keyed
     * by shift slot id.
     *
     * @param  Collection<int, ShiftSlot>  $slots
     * @return array<int, string>
     */
    public function enteredByOthersFor(User $user, Collection $slots): array
    {
        return ShiftAvailability::query()
            ->with('enteredBy')
            ->where('user_id', $user->id)
            ->whereIn('shift_slot_id', $slots->modelKeys())
            ->whereNotNull('entered_by')
            ->where('entered_by', '!=', $user->id)
            ->get()
            ->mapWithKeys(fn (ShiftAvailability $answer): array => [$answer->shift_slot_id => $answer->enteredBy?->name ?? __('someone')])
            ->all();
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
            ->where(fn (Builder $query) => $query
                ->whereIn('shift_role_id', $user->shiftRoles()->where('active', true)->select('shift_roles.id'))
                ->orWhereHas('shiftRole', fn (Builder $role) => $role->where('active', true)->where('open_to_all', true)))
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
     * The week's nights that have an unarchived event, as Y-m-d (the date an
     * event starts on; an overnight event belongs to its first night).
     *
     * @return list<string>
     */
    public function nightsFor(CarbonInterface $weekStart): array
    {
        $start = CarbonImmutable::instance(MembershipSetting::startOfScheduleWeek($weekStart))->startOfDay();
        $end = $start->addWeek();

        return Event::query()
            ->whereNull('archived_at')
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $timed) => $timed->where('starts_at', '>=', $start)->where('starts_at', '<', $end))
                ->orWhere(fn (Builder $dated) => $dated->whereNull('starts_at')->whereDate('event_date', '>=', $start->toDateString())->whereDate('event_date', '<', $end->toDateString())))
            ->get(['starts_at', 'event_date'])
            ->map(fn (Event $event): string => ($event->starts_at ?? $event->event_date)->toDateString())
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * $user's Off/Out answers for the nights, keyed Y-m-d.
     *
     * @param  list<string>  $nights
     * @return array<string, ShiftPresence>
     */
    public function presenceFor(User $user, array $nights): array
    {
        if ($nights === []) {
            return [];
        }

        return ShiftPresenceAnswer::query()
            ->where('user_id', $user->id)
            ->whereDate('night', '>=', min($nights))
            ->whereDate('night', '<=', max($nights))
            ->get()
            ->mapWithKeys(fn (ShiftPresenceAnswer $answer): array => [$answer->night->toDateString() => $answer->status])
            ->all();
    }

    /**
     * A night that's over, or whose week is published, can't change.
     */
    public function isNightLocked(string $night): bool
    {
        $date = CarbonImmutable::parse($night);

        return $date->lt(today())
            || ScheduleWeek::query()->whereDate('week_starts_on', MembershipSetting::startOfScheduleWeek($date)->toDateString())->exists();
    }

    /**
     * Saves $user's answers for the week. $answers is keyed by shift slot id;
     * null clears an answer. A slot outside what slotsFor() offers (another
     * role's, another week's, a forged id) or one already started is left
     * alone. $enteredBy is who is saving, when not $user themselves: it must
     * be allowed to enter availability for staff. $presence is Off/Out per
     * night (Y-m-d => value, null clears), for the week's event nights only.
     * Returns how many shift answers changed.
     *
     * @param  array<int|string, ShiftPreference|string|null>  $answers
     * @param  array<string, ShiftPresence|string|null>  $presence
     */
    public function save(User $user, CarbonInterface $weekStart, array $answers, ?string $note, ?User $enteredBy = null, array $presence = []): int
    {
        $enteredBy ??= $user;

        abort_unless($this->canGiveAvailability($user), 403);
        abort_unless($enteredBy->is($user) || $enteredBy->can('enter-staff-availability'), 403);

        $weekStart = MembershipSetting::startOfScheduleWeek($weekStart)->startOfDay();
        $slots = $this->slotsFor($user, $weekStart)->keyBy('id');
        $changed = 0;

        DB::transaction(function () use ($user, $enteredBy, $weekStart, $answers, $note, $presence, $slots, &$changed): void {
            $nights = $this->nightsFor($weekStart);

            foreach ($presence as $night => $value) {
                if (! in_array($night, $nights, true) || $this->isNightLocked($night)) {
                    continue;
                }

                $status = $value instanceof ShiftPresence ? $value : ShiftPresence::tryFrom((string) $value);
                $existing = ShiftPresenceAnswer::query()->where('user_id', $user->id)->whereDate('night', $night);

                if ($status === null) {
                    $existing->delete();
                } elseif ($existing->clone()->exists()) {
                    $existing->clone()->where('status', '!=', $status->value)->update(['status' => $status->value, 'entered_by' => $enteredBy->id]);
                } else {
                    ShiftPresenceAnswer::create(['user_id' => $user->id, 'entered_by' => $enteredBy->id, 'night' => $night, 'status' => $status]);
                }
            }

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
                    ['preference' => $preference, 'entered_by' => $enteredBy->id],
                );
                $changed++;
            }

            $note = filled($note) ? trim($note) : null;
            $existingNote = ShiftWeekNote::query()->where('user_id', $user->id)->whereDate('week_starts_on', $weekStart->toDateString());

            if ($note === null) {
                $existingNote->delete();
            } elseif ($existingNote->clone()->exists()) {
                // Not "update() returned 0": MySQL counts an unchanged row as 0 affected.
                $existingNote->clone()->where('note', '!=', $note)->update(['note' => $note, 'entered_by' => $enteredBy->id]);
            } else {
                ShiftWeekNote::create(['user_id' => $user->id, 'entered_by' => $enteredBy->id, 'week_starts_on' => $weekStart->toDateString(), 'note' => $note]);
            }
        });

        return $changed;
    }
}
