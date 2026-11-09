<?php

namespace App\Services;

use App\Models\MembershipSetting;
use App\Models\ShiftReward;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Cents;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Recognising someone who worked beyond the monthly shift requirement: a
 * Manager+ grants a voucher on their linked member record from Shifts
 * Worked. It's deliberately a person's decision, not automatic. Once per
 * person per month; the amount defaults to the shifts beyond the
 * requirement x staff_extra_shift_voucher_amount, and the Manager can
 * change it.
 */
class ShiftRewardService
{
    /**
     * Why $staff can't get a voucher for the month, or null if they can.
     */
    public function refusalReason(User $staff, CarbonInterface $month): ?string
    {
        $settings = MembershipSetting::current();
        $beyond = $this->shiftsBeyond($staff, $month);

        return match (true) {
            ! $settings->vouchers_enabled => __('Vouchers are turned off.'),
            $settings->staff_monthly_shift_requirement === 0 => __('There\'s no monthly shift requirement to go beyond.'),
            $beyond < 1 => __('They haven\'t worked beyond the requirement this month.'),
            $staff->member_id === null => __('Their login isn\'t linked to a member record, so there\'s nowhere to put the voucher. Link it on Users.'),
            $this->rewardFor($staff, $month) !== null => __('They already got a voucher for this month.'),
            default => null,
        };
    }

    public function shiftsWorked(User $staff, CarbonInterface $month): int
    {
        return app(ShiftWorkService::class)->workedCountsFor($month)[$staff->id] ?? 0;
    }

    public function shiftsBeyond(User $staff, CarbonInterface $month): int
    {
        return max(0, $this->shiftsWorked($staff, $month) - MembershipSetting::current()->staff_monthly_shift_requirement);
    }

    /**
     * The pre-filled amount: shifts beyond x the per-shift amount, or null
     * when no per-shift amount is set (the Manager types one).
     */
    public function suggestedAmount(User $staff, CarbonInterface $month): ?string
    {
        $perShift = MembershipSetting::current()->staff_extra_shift_voucher_amount;

        return $perShift === null ? null : Cents::toDecimal(Cents::of($perShift) * $this->shiftsBeyond($staff, $month));
    }

    public function rewardFor(User $staff, CarbonInterface $month): ?ShiftReward
    {
        return ShiftReward::query()
            ->where('user_id', $staff->id)
            ->whereDate('month', $this->monthStart($month)->toDateString())
            ->first();
    }

    /**
     * Rewards granted for the month, keyed by user id.
     *
     * @return array<int, ShiftReward>
     */
    public function rewardsFor(CarbonInterface $month): array
    {
        return ShiftReward::query()
            ->whereDate('month', $this->monthStart($month)->toDateString())
            ->get()
            ->keyBy('user_id')
            ->all();
    }

    public function grant(User $by, User $staff, CarbonInterface $month, int|string $amount, ?string $note): ShiftReward
    {
        abort_unless($by->can('record-shifts-worked'), 403);

        if (($reason = $this->refusalReason($staff, $month)) !== null) {
            throw ValidationException::withMessages(['amount' => $reason]);
        }

        $cents = Cents::of($amount);

        if ($cents <= 0) {
            throw ValidationException::withMessages(['amount' => __('The voucher has to be worth something.')]);
        }

        $month = $this->monthStart($month);
        $worked = $this->shiftsWorked($staff, $month);
        $beyond = $this->shiftsBeyond($staff, $month);
        // The voucher's reason is 255 characters, prefix included.
        $note = filled($note) ? Str::limit(trim($note), 200, '') : null;

        return DB::transaction(function () use ($by, $staff, $month, $cents, $note, $worked, $beyond): ShiftReward {
            $reason = trans_choice('Shift reward — :month (:count extra shift)|Shift reward — :month (:count extra shifts)', $beyond, ['month' => $month->isoFormat('MMMM YYYY')]);

            $voucher = Voucher::create([
                'member_id' => $staff->member_id,
                'amount' => Cents::toDecimal($cents),
                'reason' => $note === null ? $reason : $reason.': '.$note,
                'recorded_by' => $by->id,
            ]);

            return ShiftReward::create([
                'user_id' => $staff->id,
                'month' => $month->toDateString(),
                'shifts_worked' => $worked,
                'shifts_beyond' => $beyond,
                'amount' => Cents::toDecimal($cents),
                'voucher_id' => $voucher->id,
                'note' => $note,
                'granted_by' => $by->id,
            ]);
        });
    }

    private function monthStart(CarbonInterface $month): CarbonImmutable
    {
        return CarbonImmutable::instance($month)->startOfMonth();
    }
}
