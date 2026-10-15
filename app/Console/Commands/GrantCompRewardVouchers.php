<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\CommandRun;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Grants a comp reason's configured voucher to comped attendees once their
 * event has ended. Run on a timer via Windows Task Scheduler, the same way
 * backup:database already is — this app has no Laravel scheduler. See
 * docs/BLUEPRINT.md "Comp list".
 */
#[Signature('vouchers:grant-comp-rewards')]
#[Description('Grant comp-reason and workday vouchers to qualifying attendees once their event has ended.')]
class GrantCompRewardVouchers extends Command
{
    public function handle(): int
    {
        $systemEmail = config('membership.system_user_email');
        $systemUser = User::where('email', $systemEmail)->first();

        if (! $systemUser) {
            $this->error("The system user ({$systemEmail}) was not found — run the database seeder first.");
            CommandRun::recordFailure('vouchers:grant-comp-rewards', 'system user not found');

            return self::FAILURE;
        }

        $candidates = Attendance::query()
            ->whereNotNull('comp_reason_id')
            ->whereNotNull('checked_in_at') // must have actually attended, not just been listed
            ->whereHas('compReason', fn ($query) => $query->whereNotNull('grants_voucher_amount'))
            ->whereHas('event', fn ($query) => $query->whereNotNull('ends_at')->where('ends_at', '<', now()))
            // Idempotent: never grant twice for the same attendance. A voucher
            // spent at this visit is a negative row, so it doesn't count.
            ->whereDoesntHave('vouchers', fn ($query) => $query->where('amount', '>', 0))
            ->with(['compReason', 'event'])
            ->get();

        $granted = 0;

        foreach ($candidates as $attendance) {
            Voucher::create([
                'member_id' => $attendance->member_id,
                'amount' => $attendance->compReason->grants_voucher_amount,
                'reason' => "{$attendance->compReason->name} comp reward — {$attendance->event->name}",
                'attendance_id' => $attendance->id,
                'recorded_by' => $systemUser->id,
            ]);
            $granted++;
        }

        $workdayGranted = $this->grantWorkdayRewards($systemUser);

        $this->info("Comp reward vouchers granted: {$granted}");
        $this->info("Workday reward vouchers granted: {$workdayGranted}");
        CommandRun::recordSuccess('vouchers:grant-comp-rewards');

        return self::SUCCESS;
    }

    /**
     * Grants an ended workday event's voucher to each attendee who stayed at
     * least its minimum hours, measured checked_in_at to departed_at. One
     * reward per attendance, so a comped attendee rewarded above is skipped.
     */
    protected function grantWorkdayRewards(User $systemUser): int
    {
        $candidates = Attendance::query()
            ->whereNotNull('checked_in_at')
            ->whereNotNull('departed_at')
            ->whereHas('event', fn ($query) => $query
                ->whereNotNull('workday_voucher_amount')
                ->whereNotNull('workday_min_hours')
                ->whereNotNull('ends_at')
                ->where('ends_at', '<', now()))
            ->whereDoesntHave('vouchers', fn ($query) => $query->where('amount', '>', 0))
            ->with('event')
            ->get();

        $granted = 0;

        foreach ($candidates as $attendance) {
            $minutesWorked = $attendance->checked_in_at->diffInMinutes($attendance->departed_at);

            if ($minutesWorked < (float) $attendance->event->workday_min_hours * 60) {
                continue;
            }

            Voucher::create([
                'member_id' => $attendance->member_id,
                'amount' => $attendance->event->workday_voucher_amount,
                'reason' => "Workday reward — {$attendance->event->name}",
                'attendance_id' => $attendance->id,
                'recorded_by' => $systemUser->id,
            ]);
            $granted++;
        }

        return $granted;
    }
}
