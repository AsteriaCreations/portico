<?php

use App\Enums\AddOnKind;
use App\Enums\MemberStatusField;
use App\Enums\Role;
use App\Filament\Admin\Widgets\ConductTrendChartWidget;
use App\Filament\Admin\Widgets\ConductWidget;
use App\Filament\Admin\Widgets\DeskExceptionsWidget;
use App\Filament\Admin\Widgets\StaffActivityWidget;
use App\Filament\Admin\Widgets\VarianceByCloserWidget;
use App\Models\AddOn;
use App\Models\AttendanceBehaviorNote;
use App\Models\BehaviorNoteRetraction;
use App\Models\CashExchange;
use App\Models\MembershipSetting;
use App\Models\MemberStatusChange;
use App\Models\PaymentCorrection;
use App\Models\RegisterShift;
use App\Models\RoleTask;
use App\Models\RoleTaskCompletion;
use App\Models\ShiftAssignment;
use App\Models\ShiftSlot;
use App\Models\User;
use App\Models\VisitRemoval;
use App\Services\Analytics\StaffAnalytics;
use App\Support\AnalyticsPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // A Wednesday; this week is Mon 16 - Sun 22 Nov.
    $this->travelTo('2026-11-18 12:00:00');

    AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));
});

/**
 * Moves an append-only record back to $when, past the models' own guards.
 */
function backdate(Model $record, string $when): Model
{
    DB::table($record->getTable())->where('id', $record->id)->update(['created_at' => $when]);

    return $record;
}

function staffStats(string $widget, array $filters = []): array
{
    $instance = Livewire::test($widget, ['pageFilters' => $filters])->assertSuccessful()->instance();

    return collect((fn () => $this->getStats())->call($instance))
        ->keyBy(fn ($stat) => $stat->getLabel())
        ->all();
}

test('desk exceptions count each kind of after-the-fact change in the period, with its money', function () {
    VisitRemoval::factory()->create(['amount_paid' => 20]);
    VisitRemoval::factory()->create(['amount_paid' => 15, 'after_shift_closed' => true]);
    PaymentCorrection::factory()->create(['subscription_amount' => 60]);
    // A desk refund: no subscription, a negative net.
    PaymentCorrection::factory()->create(['subscription_id' => null, 'old_amount_paid' => 25, 'new_amount_paid' => 0, 'subscription_amount' => 0, 'net_amount' => -25]);
    CashExchange::factory()->create(['amount' => 40]);
    // Last month: outside the period.
    backdate(VisitRemoval::factory()->create(['amount_paid' => 999]), '2026-10-10 20:00:00');

    expect(app(StaffAnalytics::class)->deskExceptions(AnalyticsPeriod::forPreset('this_week')))->toBe([
        'removals' => 2,
        'removals_cents' => 3500,
        'removals_after_close' => 1,
        'corrections' => 1,
        'corrections_cents' => 6000,
        'refunds' => 1,
        'refunds_cents' => 2500,
        'exchanges' => 1,
        'exchanges_cents' => 4000,
    ]);

    $stats = staffStats(DeskExceptionsWidget::class);

    expect($stats['Visits removed']->getDescription())->toBe('$35.00 refunded · 1 after the shift closed')
        ->and($stats['Visits removed']->getUrl())->toContain('/admin/visit-removals')
        ->and($stats['Refunds at the desk']->getValue())->toBe(1);
});

test('the refund and cash-exchange tiles stay away for a club that has never used them', function () {
    MembershipSetting::current()->update(['desk_refunds_enabled' => false, 'cash_exchanges_enabled' => false]);

    $stats = staffStats(DeskExceptionsWidget::class);

    expect($stats)->toHaveKeys(['Visits removed', 'Entries turned into subscriptions'])
        ->not->toHaveKey('Refunds at the desk')
        ->not->toHaveKey('Cash given for electronic payments');
});

test('register variance is grouped by who closed the shift, largest first', function () {
    $alex = User::factory()->create(['name' => 'Alex']);
    $sam = User::factory()->create(['name' => 'Sam']);

    // Alex: one $10 over, one exact.
    RegisterShift::factory()->create(['opening_count' => 100, 'closing_count' => 110, 'closed_at' => now(), 'closed_by' => $alex->id]);
    RegisterShift::factory()->create(['opening_count' => 50, 'closing_count' => 50, 'closed_at' => now(), 'closed_by' => $alex->id]);
    // Sam: $30 short.
    RegisterShift::factory()->create(['opening_count' => 100, 'closing_count' => 70, 'closed_at' => now(), 'closed_by' => $sam->id]);
    // Closed last month.
    RegisterShift::factory()->create(['opening_count' => 100, 'closing_count' => 0, 'closed_at' => '2026-10-10 23:00:00', 'closed_by' => $alex->id]);

    expect(app(StaffAnalytics::class)->varianceByCloser(AnalyticsPeriod::forPreset('this_week'))->all())->toBe([
        ['name' => 'Sam', 'shifts' => 1, 'off' => 1, 'variance_cents' => -3000],
        ['name' => 'Alex', 'shifts' => 2, 'off' => 1, 'variance_cents' => 1000],
    ]);

    $stats = staffStats(VarianceByCloserWidget::class);

    expect($stats['Sam']->getValue())->toBe('-$30.00')
        ->and($stats['Sam']->getColor())->toBe('danger')
        ->and($stats['Alex']->getDescription())->toBe('1 of 2 shifts off balance');

    MembershipSetting::current()->update(['register_shifts_enabled' => false]);
    expect(VarianceByCloserWidget::canView())->toBeFalse();
});

test('staff activity counts shifts that started in the period and ticks made in it', function () {
    MembershipSetting::current()->update(['staff_scheduling_enabled' => true]);

    $thisWeek = ShiftSlot::factory()->create(['starts_at' => '2026-11-17 20:00:00', 'ends_at' => '2026-11-18 00:00:00']);
    $lastMonth = ShiftSlot::factory()->create(['starts_at' => '2026-10-10 20:00:00', 'ends_at' => '2026-10-11 00:00:00']);
    [$ana, $ben] = User::factory()->count(2)->create();

    ShiftAssignment::create(['shift_slot_id' => $thisWeek->id, 'user_id' => $ana->id, 'assigned_by' => $ana->id, 'worked_at' => '2026-11-17 20:05:00']);
    ShiftAssignment::create(['shift_slot_id' => $thisWeek->id, 'user_id' => $ben->id, 'assigned_by' => $ana->id]);
    ShiftAssignment::create(['shift_slot_id' => $lastMonth->id, 'user_id' => $ben->id, 'assigned_by' => $ana->id, 'worked_at' => '2026-10-10 20:05:00']);

    $task = RoleTask::factory()->create();
    RoleTaskCompletion::create(['role_task_id' => $task->id, 'user_id' => $ana->id, 'shift_slot_id' => $thisWeek->id, 'on_shift' => true, 'completed_at' => '2026-11-17 21:00:00']);
    RoleTaskCompletion::create(['role_task_id' => $task->id, 'user_id' => $ben->id, 'week_start' => '2026-11-16', 'on_shift' => false, 'completed_at' => '2026-11-17 22:00:00']);

    expect(app(StaffAnalytics::class)->staffActivity(AnalyticsPeriod::forPreset('this_week')))->toBe([
        'scheduled' => 2,
        'worked' => 1,
        'people' => 1,
        'ticks' => 2,
        'off_shift_ticks' => 1,
    ]);

    $stats = staffStats(StaffActivityWidget::class);

    expect($stats['Shifts worked']->getValue())->toBe('1 of 2')
        ->and($stats['Shifts worked']->getDescription())->toBe('1 person worked a shift')
        ->and($stats['Tasks ticked']->getDescription())->toBe('1 ticked off shift');

    MembershipSetting::current()->update(['staff_scheduling_enabled' => false]);
    expect(StaffActivityWidget::canView())->toBeFalse();
});

test('conduct counts notes net of retractions, and bans and watchlist changes both ways', function () {
    MembershipSetting::current()->update(['behavior_notes_enabled' => true]);

    AttendanceBehaviorNote::factory()->count(3)->create();
    BehaviorNoteRetraction::factory()->create(['attendance_behavior_note_id' => AttendanceBehaviorNote::factory()->create()->id]);
    MemberStatusChange::factory()->count(2)->create(['status' => MemberStatusField::Banned, 'value' => true]);
    MemberStatusChange::factory()->create(['status' => MemberStatusField::Banned, 'value' => false]);
    MemberStatusChange::factory()->create(['status' => MemberStatusField::Watchlist, 'value' => true]);
    // A different flag: not conduct.
    MemberStatusChange::factory()->create(['status' => MemberStatusField::MissingPaperwork, 'value' => true]);
    // Last month.
    backdate(MemberStatusChange::factory()->create(['status' => MemberStatusField::Banned, 'value' => true]), '2026-10-10 20:00:00');

    expect(app(StaffAnalytics::class)->conduct(AnalyticsPeriod::forPreset('this_week')))->toBe([
        'notes' => 4,
        'retracted' => 1,
        'banned' => 2,
        'unbanned' => 1,
        'watchlisted' => 1,
        'unwatchlisted' => 0,
    ]);

    $stats = staffStats(ConductWidget::class);

    expect($stats['Behavior notes']->getValue())->toBe(3)
        ->and($stats['Behavior notes']->getDescription())->toBe('Not counting 1 retracted')
        ->and($stats['Banned']->getDescription())->toBe('1 ban lifted');

    $chart = (fn () => $this->getData())->call(Livewire::test(ConductTrendChartWidget::class)->instance());

    expect(array_column($chart['datasets'], 'label'))->toBe(['Behavior notes', 'Banned', 'Put on the watchlist'])
        ->and(end($chart['datasets'][0]['data']))->toBe(3)
        ->and(array_sum($chart['datasets'][1]['data']))->toBe(3);
});

test('the conduct widgets name nobody and quote nothing', function () {
    AttendanceBehaviorNote::factory()->create(['note' => 'Shouted at the door volunteer']);
    $ban = MemberStatusChange::factory()->create(['reason' => 'Repeated harassment']);

    Livewire::test(ConductWidget::class)
        ->assertDontSee('Shouted at the door volunteer')
        ->assertDontSee('Repeated harassment')
        ->assertDontSee($ban->member->username);
});

test('the Operations tab carries the staff and conduct widgets', function () {
    MembershipSetting::current()->update(['staff_scheduling_enabled' => true, 'register_shifts_enabled' => true]);

    $this->get('/admin/analytics?tab=operations')
        ->assertSuccessful()
        ->assertSee('Corrections at the desk — This week')
        ->assertSee('Register variance by who closed — This week')
        ->assertSee('Staff shifts and tasks — This week')
        ->assertSee('Conduct — This week')
        ->assertSee('Conduct trend');
});
