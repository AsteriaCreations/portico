<?php

namespace App\Services;

use App\Enums\ShiftKind;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\ShiftRole;
use App\Models\ShiftSlot;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An event's staffing (the Staffing page, Manager+). The standard shifts are
 * set together: Open and Close, or one Whole night shift on the club's
 * single-shift nights, each with a number of people per role. Open runs
 * from the event's start to the handover; Close starts staff_handover_minutes
 * before Open ends and runs to the event's end, so the two overlap. Setup
 * and extra (partial-coverage) shifts are added one at a time with their own
 * times. Changing the numbers updates shifts in place, so nobody already
 * scheduled is lost, and refuses a change that would drop someone.
 */
class ShiftSlotService
{
    public const SHAPE_OPEN_CLOSE = 'open_close';

    public const SHAPE_SINGLE = 'single';

    private const STANDARD_KINDS = [ShiftKind::Open, ShiftKind::Close, ShiftKind::Single];

    /**
     * The roles the standard shifts are counted in (Desk, Rover, DM…): every
     * active role except ones open to everyone, like Setup.
     *
     * @return Collection<int, ShiftRole>
     */
    public function standardRoles(): Collection
    {
        return ShiftRole::query()->where('active', true)->where('open_to_all', false)->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * The shape the event has, or would get by default: one shift on the
     * days set in Membership Settings, Open and Close otherwise.
     */
    public function shapeFor(Event $event): string
    {
        $standard = $event->shiftSlots()->whereIn('kind', self::STANDARD_KINDS)->pluck('kind');

        if ($standard->isNotEmpty()) {
            return $standard->contains(ShiftKind::Single) ? self::SHAPE_SINGLE : self::SHAPE_OPEN_CLOSE;
        }

        $day = ($event->starts_at ?? $event->event_date)?->dayOfWeek;
        $singleDays = array_map('intval', MembershipSetting::current()->staff_single_shift_days ?? []);

        return in_array($day, $singleDays, true) ? self::SHAPE_SINGLE : self::SHAPE_OPEN_CLOSE;
    }

    /**
     * When Open ends: the event's own Open shift if it has one, else halfway
     * through the event, to the nearest half hour.
     */
    public function openEndsAtFor(Event $event): ?CarbonInterface
    {
        $open = $event->shiftSlots()->where('kind', ShiftKind::Open)->value('ends_at');

        if ($open !== null) {
            return Carbon::parse($open);
        }

        if ($event->starts_at === null || $event->ends_at === null) {
            return null;
        }

        return $event->starts_at->copy()
            ->addSeconds(intdiv((int) $event->starts_at->diffInSeconds($event->ends_at), 2))
            ->roundMinute(30);
    }

    /**
     * The event's current standard counts, kind value => role id => people.
     *
     * @return array<string, array<int, int>>
     */
    public function countsFor(Event $event): array
    {
        $counts = [];

        foreach ($event->shiftSlots()->whereIn('kind', self::STANDARD_KINDS)->get() as $slot) {
            $counts[$slot->kind->value][$slot->shift_role_id] = $slot->headcount;
        }

        return $counts;
    }

    /**
     * Sets the event's standard shifts. $counts is kind value => role id =>
     * people; a role at 0 (or left out) gets no shift of that kind. Throws a
     * ValidationException rather than drop or squeeze out someone already
     * scheduled. Returns how many standard shifts the event has afterwards.
     *
     * @param  array<string, array<int|string, int|string|null>>  $counts
     */
    public function setStandardShifts(Event $event, string $shape, ?CarbonInterface $openEndsAt, array $counts): int
    {
        if ($event->starts_at === null || $event->ends_at === null) {
            throw ValidationException::withMessages(['shape' => __('Give the event a start and end time first.')]);
        }

        $windows = $this->windows($event, $shape, $openEndsAt);
        $roles = $this->standardRoles();

        return DB::transaction(function () use ($event, $windows, $counts, $roles): int {
            $existing = $event->shiftSlots()
                ->whereIn('kind', self::STANDARD_KINDS)
                ->withCount('assignments')
                ->with('shiftRole')
                ->get();

            $kept = [];

            foreach ($windows as $kind => [$startsAt, $endsAt]) {
                foreach ($roles as $role) {
                    $people = max(0, (int) ($counts[$kind][$role->id] ?? 0));

                    if ($people === 0) {
                        continue;
                    }

                    $slot = $existing->first(fn (ShiftSlot $slot): bool => $slot->kind->value === $kind && $slot->shift_role_id === $role->id);

                    if ($slot !== null && $slot->assignments_count > $people) {
                        throw ValidationException::withMessages(["counts.{$kind}.{$role->id}" => trans_choice(':count person is already scheduled on :shift; take them off on the Staff schedule first.|:count people are already scheduled on :shift; take some off on the Staff schedule first.', $slot->assignments_count, ['shift' => $slot->displayName()])]);
                    }

                    $attributes = ['starts_at' => $startsAt, 'ends_at' => $endsAt, 'headcount' => $people];

                    if ($slot === null) {
                        $slot = $event->shiftSlots()->create(['shift_role_id' => $role->id, 'kind' => $kind, ...$attributes]);
                    } else {
                        $slot->update($attributes);
                    }

                    $kept[] = $slot->id;
                }
            }

            foreach ($existing->whereNotIn('id', $kept) as $slot) {
                if ($slot->assignments_count > 0) {
                    throw ValidationException::withMessages(['shape' => __('Someone is scheduled on :shift; take them off on the Staff schedule before removing it.', ['shift' => $slot->displayName()])]);
                }

                $slot->delete();
            }

            return count($kept);
        });
    }

    /**
     * Gives $to the same shifts as $from (a duplicated or repeated event),
     * moved by the difference between their start times (or dates), with
     * nobody scheduled.
     */
    public function copyShifts(Event $from, Event $to): void
    {
        $fromAnchor = $from->starts_at ?? $from->event_date;
        $toAnchor = $to->starts_at ?? $to->event_date;

        if ($fromAnchor === null || $toAnchor === null) {
            return;
        }

        $seconds = (int) Carbon::parse($fromAnchor)->diffInSeconds(Carbon::parse($toAnchor), false);

        foreach ($from->shiftSlots()->get() as $slot) {
            $to->shiftSlots()->create([
                'shift_role_id' => $slot->shift_role_id,
                'kind' => $slot->kind,
                'label' => $slot->label,
                'starts_at' => $slot->starts_at->copy()->addSeconds($seconds),
                'ends_at' => $slot->ends_at->copy()->addSeconds($seconds),
                'headcount' => $slot->headcount,
                'notes' => $slot->notes,
            ]);
        }
    }

    /**
     * Each standard kind's start and end for the shape.
     *
     * @return array<string, array{0: CarbonInterface, 1: CarbonInterface}>
     */
    private function windows(Event $event, string $shape, ?CarbonInterface $openEndsAt): array
    {
        if ($shape === self::SHAPE_SINGLE) {
            return [ShiftKind::Single->value => [$event->starts_at, $event->ends_at]];
        }

        $handover = MembershipSetting::current()->staff_handover_minutes;
        $closeStartsAt = $openEndsAt?->copy()->subMinutes($handover);

        if ($openEndsAt === null || $closeStartsAt->lte($event->starts_at) || $openEndsAt->gte($event->ends_at)) {
            throw ValidationException::withMessages(['open_ends_at' => trans_choice('Open has to end before the event does, and more than :count minute after it starts, since Close starts :count minute before Open ends.|Open has to end before the event does, and more than :count minutes after it starts, since Close starts :count minutes before Open ends.', $handover)]);
        }

        return [
            ShiftKind::Open->value => [$event->starts_at, $openEndsAt],
            ShiftKind::Close->value => [$closeStartsAt, $event->ends_at],
        ];
    }
}
