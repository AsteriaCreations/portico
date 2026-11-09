<?php

namespace App\Services;

use App\Models\MembershipSetting;
use App\Models\ShiftAssignment;
use App\Models\ShiftSlot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who actually worked each shift, and the monthly count against the club's
 * shift requirement (staff_monthly_shift_requirement, default 3; 0 = none).
 * A scheduled spot is marked worked when the person signs in during the
 * shift (from SIGN_IN_EARLY_MINUTES before it starts until it ends), or by
 * a Manager+ on the Staffing page, who can also add someone who
 * covered without being scheduled. Only shifts of roles that count toward
 * the requirement (shift_roles.counts_toward_requirement, off for e.g.
 * Host) are counted; a shift belongs to the month it starts in.
 */
class ShiftWorkService
{
    public const SIGN_IN_EARLY_MINUTES = 60;

    /**
     * Signing in marks any of $user's shifts running now as worked. Returns
     * the shifts it marked.
     *
     * @return Collection<int, ShiftSlot>
     */
    public function recordSignIn(User $user): Collection
    {
        if (! MembershipSetting::current()->staff_scheduling_enabled) {
            return new Collection;
        }

        $now = now();

        $assignments = ShiftAssignment::query()
            ->with('shiftSlot.shiftRole')
            ->where('user_id', $user->id)
            ->whereNull('worked_at')
            ->whereHas('shiftSlot', fn (Builder $query) => $query
                ->where('starts_at', '<=', $now->copy()->addMinutes(self::SIGN_IN_EARLY_MINUTES))
                ->where('ends_at', '>', $now))
            ->get();

        foreach ($assignments as $assignment) {
            $assignment->update(['worked_at' => $now, 'worked_source' => ShiftAssignment::WORKED_BY_SIGN_IN, 'worked_recorded_by' => $user->id]);
        }

        return new Collection($assignments->pluck('shiftSlot')->all());
    }

    /**
     * Manager+ records who worked $slot: $workedIds are the people who did.
     * Anyone scheduled and not in it is unmarked; anyone in it who wasn't
     * scheduled (covering) is added to the shift as worked. Only once the
     * shift has started. Returns how many people changed.
     *
     * @param  list<int|string>  $workedIds
     */
    public function recordWorked(ShiftSlot $slot, User $by, array $workedIds): int
    {
        abort_unless($by->can('record-shifts-worked'), 403);
        abort_if($slot->starts_at->isFuture(), 403);

        $workedIds = collect($workedIds)->map(fn ($id): int => (int) $id)->unique();
        $eligible = $slot->shiftRole->eligibleUsers()->modelKeys();
        $changed = 0;

        DB::transaction(function () use ($slot, $by, $workedIds, $eligible, &$changed): void {
            $assignments = $slot->assignments()->get()->keyBy('user_id');

            foreach ($assignments as $userId => $assignment) {
                $worked = $workedIds->contains($userId);

                if ($worked && $assignment->worked_at === null) {
                    $assignment->update(['worked_at' => now(), 'worked_source' => ShiftAssignment::WORKED_BY_MANAGER, 'worked_recorded_by' => $by->id]);
                    $changed++;
                } elseif (! $worked && $assignment->worked_at !== null) {
                    $assignment->update(['worked_at' => null, 'worked_source' => null, 'worked_recorded_by' => $by->id]);
                    $changed++;
                }
            }

            foreach ($workedIds->diff($assignments->keys()) as $userId) {
                // Covering: only someone who could have been scheduled on it.
                if (! in_array($userId, $eligible, true)) {
                    continue;
                }

                $slot->assignments()->create([
                    'user_id' => $userId,
                    'assigned_by' => $by->id,
                    'worked_at' => now(),
                    'worked_source' => ShiftAssignment::WORKED_BY_MANAGER,
                    'worked_recorded_by' => $by->id,
                ]);
                $changed++;
            }
        });

        return $changed;
    }

    /**
     * Shifts each person worked in the month containing $month that count
     * toward the requirement, keyed by user id.
     *
     * @return array<int, int>
     */
    public function workedCountsFor(CarbonInterface $month): array
    {
        $start = CarbonImmutable::instance($month)->startOfMonth();

        return ShiftAssignment::query()
            ->whereNotNull('worked_at')
            ->whereHas('shiftSlot', fn (Builder $query) => $query
                ->where('starts_at', '>=', $start)
                ->where('starts_at', '<', $start->addMonth())
                ->whereHas('shiftRole', fn (Builder $role) => $role->where('counts_toward_requirement', true)))
            ->select('user_id', DB::raw('count(*) as worked'))
            ->groupBy('user_id')
            ->pluck('worked', 'user_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    public function requirement(): int
    {
        return MembershipSetting::current()->staff_monthly_shift_requirement;
    }
}
