<?php

namespace App\Services\Analytics;

use App\Enums\MemberStatusField;
use App\Models\RegisterShift;
use App\Services\RegisterShiftService;
use App\Support\AnalyticsPeriod;
use App\Support\Cents;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How the staff side ran, for the Analytics page: the desk's corrections
 * and exceptions, how registers balanced by who closed them, shifts and
 * task lists, and conduct records. Counts and amounts only: never a
 * member's name, a reason or a note's text.
 */
class StaffAnalytics
{
    public function __construct(private RegisterShiftService $registerShifts) {}

    /**
     * Each kind of after-the-fact desk change recorded in the period, with
     * the money it moved.
     *
     * @return array{removals: int, removals_cents: int, removals_after_close: int, corrections: int, corrections_cents: int, refunds: int, refunds_cents: int, exchanges: int, exchanges_cents: int}
     */
    public function deskExceptions(AnalyticsPeriod $period): array
    {
        $removals = $period->constrain(DB::table('visit_removals'), 'created_at');
        // A desk refund is a payment correction with no subscription
        // (DeskRefundService); one with a subscription turned a paid entry
        // into a subscription (EntryCorrectionService).
        $corrections = $period->constrain(DB::table('payment_corrections'), 'created_at')->whereNotNull('subscription_id');
        $refunds = $period->constrain(DB::table('payment_corrections'), 'created_at')->whereNull('subscription_id');
        $exchanges = $period->constrain(DB::table('cash_exchanges'), 'created_at');

        return [
            'removals' => (clone $removals)->count(),
            'removals_cents' => Cents::of((clone $removals)->sum('amount_paid')),
            'removals_after_close' => (clone $removals)->where('after_shift_closed', true)->count(),
            'corrections' => (clone $corrections)->count(),
            'corrections_cents' => Cents::of((clone $corrections)->sum('subscription_amount')),
            'refunds' => (clone $refunds)->count(),
            'refunds_cents' => -Cents::of((clone $refunds)->sum('net_amount')),
            'exchanges' => (clone $exchanges)->count(),
            'exchanges_cents' => Cents::of((clone $exchanges)->sum('amount')),
        ];
    }

    /**
     * Register shifts closed in the period, by the person who closed them:
     * how many, how many didn't balance, and the net variance. Largest
     * absolute variance first.
     *
     * @return Collection<int, array{name: string, shifts: int, off: int, variance_cents: int}>
     */
    public function varianceByCloser(AnalyticsPeriod $period): Collection
    {
        return $period->constrain(RegisterShift::query(), 'closed_at')
            ->with('closedBy')
            ->get()
            ->groupBy('closed_by')
            ->map(function (Collection $shifts): array {
                $variances = $shifts->map(fn (RegisterShift $shift): int => $this->registerShifts->varianceCents($shift) ?? 0);

                return [
                    'name' => $shifts->first()->closedBy?->name ?? __('Unknown'),
                    'shifts' => $shifts->count(),
                    'off' => $variances->filter(fn (int $variance): bool => $variance !== 0)->count(),
                    'variance_cents' => (int) $variances->sum(),
                ];
            })
            ->sortByDesc(fn (array $closer): int => abs($closer['variance_cents']))
            ->values();
    }

    /**
     * Staff shifts that started in the period and task-list ticks made in
     * it. A shift counts as worked once it is marked worked (signing in, or
     * a Manager); an off-shift tick is one made on a shift the person
     * wasn't on.
     *
     * @return array{scheduled: int, worked: int, people: int, ticks: int, off_shift_ticks: int}
     */
    public function staffActivity(AnalyticsPeriod $period): array
    {
        $assignments = $period->constrain(
            DB::table('shift_assignments')->join('shift_slots', 'shift_slots.id', '=', 'shift_assignments.shift_slot_id'),
            'shift_slots.starts_at',
        );
        $ticks = $period->constrain(DB::table('role_task_completions'), 'completed_at');

        return [
            'scheduled' => (clone $assignments)->count(),
            'worked' => (clone $assignments)->whereNotNull('shift_assignments.worked_at')->count(),
            'people' => (clone $assignments)->whereNotNull('shift_assignments.worked_at')->distinct('shift_assignments.user_id')->count('shift_assignments.user_id'),
            'ticks' => (clone $ticks)->count(),
            'off_shift_ticks' => (clone $ticks)->where('on_shift', false)->count(),
        ];
    }

    /**
     * Conduct records made in the period: behavior notes written (and how
     * many of those have since been retracted), and members put on or taken
     * off a ban or the watchlist.
     *
     * @return array{notes: int, retracted: int, banned: int, unbanned: int, watchlisted: int, unwatchlisted: int}
     */
    public function conduct(AnalyticsPeriod $period): array
    {
        $notes = $period->constrain(DB::table('attendance_behavior_notes'), 'attendance_behavior_notes.created_at');
        $changes = fn (MemberStatusField $status, bool $value): int => $period->constrain(DB::table('member_status_changes'), 'created_at')
            ->where('status', $status->value)
            ->where('value', $value)
            ->count();

        return [
            'notes' => (clone $notes)->count(),
            'retracted' => (clone $notes)
                ->join('behavior_note_retractions', 'behavior_note_retractions.attendance_behavior_note_id', '=', 'attendance_behavior_notes.id')
                ->count(),
            'banned' => $changes(MemberStatusField::Banned, true),
            'unbanned' => $changes(MemberStatusField::Banned, false),
            'watchlisted' => $changes(MemberStatusField::Watchlist, true),
            'unwatchlisted' => $changes(MemberStatusField::Watchlist, false),
        ];
    }
}
