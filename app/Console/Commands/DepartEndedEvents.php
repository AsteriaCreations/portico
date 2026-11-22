<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\CommandRun;
use App\Models\MembershipSetting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Marks everyone still on site as departed once their event has ended, so
 * nobody who walked out without being checked out keeps counting toward
 * occupancy or shows on Active Patrons. Waits for ends_at plus the event
 * window buffer (the same grace Event::currentQuery() gives stragglers),
 * then stamps departed_at with ends_at itself, or the arrival time for
 * someone who arrived after it, so time-on-site stays honest in the
 * analytics and workday rewards. Skips prepays who never arrived and
 * overnight stays, which stay until checked out. Run hourly from Windows
 * Task Scheduler, like events:notify-ended; does nothing while the
 * auto_depart_at_event_end flag is off.
 */
#[Signature('events:depart-ended')]
#[Description('Mark everyone still on site as departed once their event has ended.')]
class DepartEndedEvents extends Command
{
    public function handle(): int
    {
        $settings = MembershipSetting::current();

        if (! $settings->auto_depart_at_event_end) {
            $this->info('Auto-depart is off (Feature Flags).');

            return self::SUCCESS;
        }

        $attendances = Attendance::query()
            ->whereNotNull('checked_in_at')
            ->whereNull('departed_at')
            ->whereHas('event', fn ($query) => $query
                ->whereNotNull('ends_at')
                ->where('ends_at', '<', now()->subMinutes($settings->event_window_buffer_minutes)))
            ->whereDoesntHave('addOns', fn ($query) => $query->where('is_overnight', true))
            ->with('event')
            ->get();

        foreach ($attendances as $attendance) {
            $attendance->update(['departed_at' => $attendance->checked_in_at->max($attendance->event->ends_at)]);
        }

        $this->info("Departed: {$attendances->count()}");
        CommandRun::recordSuccess('events:depart-ended');

        return self::SUCCESS;
    }
}
