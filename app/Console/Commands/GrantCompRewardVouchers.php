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
#[Description("Grant a comp reason's configured voucher to comped attendees once their event has ended.")]
class GrantCompRewardVouchers extends Command
{
    public function handle(): int
    {
        $systemUser = User::where('email', 'system@portico.internal')->first();

        if (! $systemUser) {
            $this->error('The system user (system@portico.internal) was not found — run the database seeder first.');
            CommandRun::recordFailure('vouchers:grant-comp-rewards', 'system user not found');

            return self::FAILURE;
        }

        $candidates = Attendance::query()
            ->whereNotNull('comp_reason_id')
            ->whereNotNull('checked_in_at') // must have actually attended, not just been listed
            ->whereHas('compReason', fn ($query) => $query->whereNotNull('grants_voucher_amount'))
            ->whereHas('event', fn ($query) => $query->whereNotNull('ends_at')->where('ends_at', '<', now()))
            ->whereDoesntHave('vouchers') // idempotent: never grant twice for the same attendance
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

        $this->info("Comp reward vouchers granted: {$granted}");
        CommandRun::recordSuccess('vouchers:grant-comp-rewards');

        return self::SUCCESS;
    }
}
