<?php

namespace App\Services;

use App\Enums\ShiftKind;
use App\Enums\ShiftPresence;
use App\Models\ShiftAssignment;
use App\Models\ShiftPresenceAnswer;
use App\Models\ShiftSlot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The week laid out the way the club's paper schedule is:
 *  - columns: an Open and a Close column for each event that has Open and
 *    Close shifts (usually Friday and Saturday);
 *  - rows: the roles on those shifts, each cell the people on it;
 *  - side: Setup and labelled or extra shifts (setup, resets, late cover);
 *  - weekday: the one-shift nights;
 *  - off / out: for each night, who isn't on a shift but will be there
 *    (Off) or won't (Out), from My availability.
 */
class ShiftGridService
{
    /**
     * @return array{
     *     columns: list<array{key: string, day: string, kind: string, event: string}>,
     *     rows: list<array{role: string, cells: array<string, array{names: list<string>, open: int}|null>}>,
     *     side: list<array{shift: string, when: string, names: list<string>, open: int}>,
     *     weekday: list<array{day: string, event: string, shifts: list<array{shift: string, names: list<string>, open: int}>}>,
     *     nights: list<array{day: string, off: list<string>, out: list<string>}>,
     * }
     */
    public function build(CarbonInterface $weekStart, bool $publishedOnly): array
    {
        $schedule = app(ShiftScheduleService::class);
        $slots = $publishedOnly ? $schedule->publishedSlotsFor($weekStart) : $schedule->slotsFor($weekStart);

        $main = $slots->filter(fn (ShiftSlot $slot): bool => in_array($slot->kind, [ShiftKind::Open, ShiftKind::Close], true));
        $columns = $main
            ->sortBy(fn (ShiftSlot $slot): string => $slot->event->starts_at?->format('YmdHi').'|'.$slot->event_id.'|'.($slot->kind === ShiftKind::Open ? 0 : 1))
            ->map(fn (ShiftSlot $slot): array => [
                'key' => $slot->event_id.'-'.$slot->kind->value,
                'day' => ($slot->event->starts_at ?? $slot->event->event_date)->isoFormat('ddd'),
                'kind' => $slot->kind->getLabel(),
                'event' => $slot->event->name,
            ])
            ->unique('key')
            ->values()
            ->all();

        $rows = $main
            ->groupBy('shift_role_id')
            ->sortBy(fn (Collection $roleSlots): string => sprintf('%06d', $roleSlots->first()->shiftRole->sort_order).$roleSlots->first()->shiftRole->name)
            ->map(fn (Collection $roleSlots): array => [
                'role' => $roleSlots->first()->shiftRole->name,
                'cells' => collect($columns)->mapWithKeys(function (array $column) use ($roleSlots): array {
                    $slot = $roleSlots->first(fn (ShiftSlot $slot): bool => $slot->event_id.'-'.$slot->kind->value === $column['key']);

                    return [$column['key'] => $slot === null ? null : $this->people($slot)];
                })->all(),
            ])
            ->values()
            ->all();

        $side = $slots
            ->filter(fn (ShiftSlot $slot): bool => in_array($slot->kind, [ShiftKind::Setup, ShiftKind::Extra], true))
            ->sortBy('starts_at')
            ->map(fn (ShiftSlot $slot): array => [
                'shift' => $slot->displayName(),
                'when' => __(':day :start to :end', ['day' => $slot->starts_at->isoFormat('ddd'), 'start' => $slot->starts_at->isoFormat('LT'), 'end' => $slot->ends_at->isoFormat('LT')]),
                ...$this->people($slot),
            ])
            ->values()
            ->all();

        $weekday = $slots
            ->filter(fn (ShiftSlot $slot): bool => $slot->kind === ShiftKind::Single)
            ->sortBy('starts_at')
            ->groupBy('event_id')
            ->map(fn (Collection $eventSlots): array => [
                'day' => ($eventSlots->first()->event->starts_at ?? $eventSlots->first()->event->event_date)->isoFormat('ddd'),
                'event' => $eventSlots->first()->event->name,
                'shifts' => $eventSlots
                    ->sortBy(fn (ShiftSlot $slot): string => sprintf('%06d', $slot->shiftRole->sort_order).$slot->shiftRole->name)
                    ->map(fn (ShiftSlot $slot): array => ['shift' => $slot->displayName(), ...$this->people($slot)])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        return [
            'columns' => $columns,
            'rows' => $rows,
            'side' => $side,
            'weekday' => $weekday,
            'nights' => $slots->isEmpty() ? [] : $this->offAndOut($weekStart, $slots),
        ];
    }

    /**
     * @return array{names: list<string>, open: int}
     */
    private function people(ShiftSlot $slot): array
    {
        $names = $slot->assignments->map(fn (ShiftAssignment $assignment): ?string => $assignment->user?->name)->filter()->sort()->values()->all();

        return ['names' => $names, 'open' => max(0, $slot->headcount - count($names))];
    }

    /**
     * For each event night: who said Off or Out and isn't on any shift that
     * night.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, ShiftSlot>  $slots
     * @return list<array{day: string, off: list<string>, out: list<string>}>
     */
    private function offAndOut(CarbonInterface $weekStart, $slots): array
    {
        $nights = app(ShiftAvailabilityService::class)->nightsFor($weekStart);

        if ($nights === []) {
            return [];
        }

        $workingByNight = $slots
            ->groupBy(fn (ShiftSlot $slot): string => ($slot->event->starts_at ?? $slot->event->event_date)->toDateString())
            ->map(fn (Collection $nightSlots): array => $nightSlots->flatMap(fn (ShiftSlot $slot) => $slot->assignments->pluck('user_id'))->unique()->all());

        $answers = ShiftPresenceAnswer::query()
            ->with('user')
            ->whereDate('night', '>=', min($nights))
            ->whereDate('night', '<=', max($nights))
            ->whereHas('user', fn ($query) => $query->where('active', true))
            ->get()
            ->groupBy(fn (ShiftPresenceAnswer $answer): string => $answer->night->toDateString());

        return collect($nights)
            ->map(function (string $night) use ($answers, $workingByNight): array {
                $notWorking = ($answers->get($night) ?? collect())
                    ->reject(fn (ShiftPresenceAnswer $answer): bool => in_array($answer->user_id, $workingByNight->get($night, []), true));

                $names = fn (ShiftPresence $status): array => $notWorking
                    ->filter(fn (ShiftPresenceAnswer $answer): bool => $answer->status === $status)
                    ->map(fn (ShiftPresenceAnswer $answer): string => $answer->user->name)
                    ->sort()
                    ->values()
                    ->all();

                return ['day' => CarbonImmutable::parse($night)->isoFormat('ddd'), 'off' => $names(ShiftPresence::Off), 'out' => $names(ShiftPresence::Out)];
            })
            ->filter(fn (array $night): bool => $night['off'] !== [] || $night['out'] !== [])
            ->values()
            ->all();
    }
}
