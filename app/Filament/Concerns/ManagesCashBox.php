<?php

namespace App\Filament\Concerns;

use App\Enums\Role;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\MiscellaneousPayment;
use App\Models\PaymentMethod;
use App\Models\Register;
use App\Models\RegisterShift;
use App\Services\InstructorPayoutResult;
use App\Services\InstructorPayoutService;
use App\Services\RegisterShiftService;
use App\Support\Cents;
use Carbon\CarbonInterface;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/**
 * The Check-In Desk's cash-box actions: open the box, record a drop or a
 * miscellaneous payment, pay (or correct) an event's instructor, and close
 * the box with its envelope reminder. Pulled out of CheckIn so the page
 * file holds the check-in flow; behaviour is unchanged.
 *
 * Relies on the host page for getOpenShift(), getCurrentRegister(),
 * paymentMethodOptions(), haltForTraining() and formatCurrency().
 */
trait ManagesCashBox
{
    public function openShiftAction(): Action
    {
        return Action::make('openShift')
            ->label(__('Open Box'))
            ->schema([
                TextInput::make('opening_count')
                    ->label(__('Opening count'))
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->default(MembershipSetting::current()->default_opening_float)
                    ->required(),
            ])
            ->visible(fn (): bool => $this->getCurrentRegister() && ! $this->getOpenShift())
            ->action(function (array $data): void {
                if ($this->haltForTraining(__('Practice: cash-box action simulated.'))) {
                    return;
                }

                abort_unless(MembershipSetting::current()->register_shifts_enabled, 403);

                $register = $this->getCurrentRegister();
                abort_unless($register, 404);

                app(RegisterShiftService::class)->openShift($register, auth()->user(), (float) $data['opening_count']);

                Notification::make()->title(__('Box opened'))->success()->send();
            });
    }

    public function recordDropAction(): Action
    {
        return Action::make('recordDrop')
            ->label(__('Record a drop'))
            ->schema([
                TextInput::make('amount')
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->required(),
                Textarea::make('reason')
                    ->maxLength(255),
            ])
            ->visible(fn (): bool => (bool) $this->getOpenShift())
            ->action(function (array $data): void {
                if ($this->haltForTraining(__('Practice: cash-box action simulated.'))) {
                    return;
                }

                abort_unless(MembershipSetting::current()->register_shifts_enabled, 403);

                $shift = $this->getOpenShift();
                abort_unless($shift, 404);

                app(RegisterShiftService::class)->recordDrop($shift, auth()->user(), (float) $data['amount'], $data['reason'] ?? null);

                Notification::make()->title(__('Drop recorded'))->success()->send();
            });
    }

    // Cash (or other payment) taken at the register for something that never
    // touches Attendance/Subscription at all -- a vendor payment, a private
    // rental, a donation. notation is required free text rather than a fixed
    // reason enum, since these are inherently one-off. See
    // MiscellaneousPayment / RegisterShiftService::recordMiscPayment().
    public function recordMiscPaymentAction(): Action
    {
        return Action::make('recordMiscPayment')
            ->label(__('Record other payment'))
            ->schema([
                Select::make('payment_method')
                    ->options(PaymentMethod::options())
                    ->required(),
                TextInput::make('amount')
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->required(),
                Textarea::make('notation')
                    ->label(__('What was this for?'))
                    ->required()
                    ->maxLength(255),
            ])
            ->visible(fn (): bool => (bool) $this->getOpenShift())
            ->action(function (array $data): void {
                if ($this->haltForTraining(__('Practice: cash-box action simulated.'))) {
                    return;
                }

                abort_unless(MembershipSetting::current()->register_shifts_enabled, 403);

                $shift = $this->getOpenShift();
                abort_unless($shift, 404);

                app(RegisterShiftService::class)->recordMiscPayment(
                    $shift,
                    auth()->user(),
                    (float) $data['amount'],
                    $data['payment_method'],
                    $data['notation'],
                );

                Notification::make()->title(__('Payment recorded'))->success()->send();
            });
    }

    /**
     * Instructor-paid events on the desk right now, keyed id => name, either
     * unpaid (Pay instructor) or with a payout standing (Manager+
     * correction). "Paid" is net of corrections, so a payout reversed to $0
     * counts as unpaid again.
     *
     * @return array<int, string>
     */
    private function instructorPayoutEventOptions(bool $paid): array
    {
        if (! MembershipSetting::current()->instructor_payouts_enabled) {
            return [];
        }

        return app(InstructorPayoutService::class)->currentEventsQuery()
            ->withSum('instructorPayouts', 'amount')
            ->get()
            ->filter(fn (Event $event): bool => (Cents::of($event->instructor_payouts_sum_amount) !== 0) === $paid)
            ->pluck('name', 'id')
            ->all();
    }

    private function calculatedInstructorPayout(mixed $eventId): ?InstructorPayoutResult
    {
        $event = $eventId ? Event::find($eventId) : null;

        return $event ? app(InstructorPayoutService::class)->calculate($event) : null;
    }

    private function instructorPayoutHelperText(mixed $eventId): ?string
    {
        $result = $this->calculatedInstructorPayout($eventId);

        if (! $result) {
            return null;
        }

        $lines = collect($result->lineItems)
            ->map(fn (array $line) => __(':source :count × :rate', [
                'source' => $line['source']->getLabel(),
                'count' => $line['count'],
                'rate' => MembershipSetting::formatMoney(Cents::toFloat($line['rateCents'])),
            ]))
            ->implode(' · ');

        return __('Calculated: :total', ['total' => MembershipSetting::formatMoney(Cents::toFloat($result->totalCents))])
            .($lines !== '' ? " ({$lines})" : '');
    }

    // Cash handed to tonight's instructor out of this box, so the close-out
    // still balances (RegisterShiftService subtracts it from expected cash).
    // The amount starts at InstructorPayoutService's total but stays editable
    // (rounding, an agreed different amount); the calculated figure is
    // snapshotted alongside it. Once per event: the event drops out of the
    // options after it's paid, and the service re-checks under a lock.
    public function payInstructorAction(): Action
    {
        return Action::make('payInstructor')
            ->label(__('Pay instructor'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->modalDescription(__('Records cash handed to the instructor out of this box. Each event\'s instructor can be paid once here; a Manager can correct it afterwards.'))
            ->fillForm(function (): array {
                $options = $this->instructorPayoutEventOptions(paid: false);
                $deskEventId = (int) ($this->data['event_id'] ?? 0);
                $eventId = array_key_exists($deskEventId, $options) ? $deskEventId : array_key_first($options);

                return [
                    'event_id' => $eventId,
                    'amount' => Cents::toFloat($this->calculatedInstructorPayout($eventId)?->totalCents ?? 0),
                ];
            })
            ->schema([
                Select::make('event_id')
                    ->label(__('Event'))
                    ->options(fn (): array => $this->instructorPayoutEventOptions(paid: false))
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn ($state, callable $set) => $set('amount', Cents::toFloat($this->calculatedInstructorPayout($state)?->totalCents ?? 0))),
                TextInput::make('amount')
                    ->label(__('Amount paid'))
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->required()
                    ->helperText(fn (Get $get): ?string => $this->instructorPayoutHelperText($get('event_id'))),
                Textarea::make('notes')
                    ->maxLength(255),
            ])
            ->visible(fn (): bool => $this->getOpenShift() && $this->instructorPayoutEventOptions(paid: false) !== [])
            ->action(function (array $data): void {
                if ($this->haltForTraining(__('Practice: cash-box action simulated.'))) {
                    return;
                }

                abort_unless(MembershipSetting::current()->register_shifts_enabled, 403);
                abort_unless(array_key_exists((int) $data['event_id'], $this->instructorPayoutEventOptions(paid: false)), 403);

                $shift = $this->getOpenShift();
                abort_unless($shift, 404);

                app(RegisterShiftService::class)->recordInstructorPayout(
                    $shift,
                    Event::findOrFail($data['event_id']),
                    auth()->user(),
                    (float) $data['amount'],
                    filled($data['notes'] ?? null) ? $data['notes'] : null,
                );

                Notification::make()->title(__('Instructor payout recorded'))->success()->send();
            });
    }

    // The Manager+ fix for a wrong instructor payout: a further signed row
    // with a required note (negative = the instructor handed money back),
    // never an edit. Still needs this box open, so a closed shift's expected
    // cash never moves.
    public function correctInstructorPayoutAction(): Action
    {
        return Action::make('correctInstructorPayout')
            ->label(__('Correct instructor payout'))
            ->color('gray')
            ->modalDescription(__('Adds a correction to an instructor payout already recorded tonight. Enter a negative amount for money the instructor gave back.'))
            ->fillForm(fn (): array => ['event_id' => array_key_first($this->instructorPayoutEventOptions(paid: true))])
            ->schema([
                Select::make('event_id')
                    ->label(__('Event'))
                    ->options(fn (): array => $this->instructorPayoutEventOptions(paid: true))
                    ->required(),
                TextInput::make('amount')
                    ->label(__('Correction amount'))
                    ->numeric()
                    ->step(0.01)
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (Cents::of($value) === 0) {
                            $fail(__('Enter a non-zero amount.'));
                        }
                    })
                    ->required()
                    ->helperText(__('Positive pays the instructor more; negative is money they handed back.')),
                Textarea::make('notes')
                    ->label(__('Reason'))
                    ->required()
                    ->maxLength(255),
            ])
            ->visible(fn (): bool => $this->getOpenShift()
                && auth()->user()->role->atLeast(Role::Manager)
                && $this->instructorPayoutEventOptions(paid: true) !== [])
            ->action(function (array $data): void {
                if ($this->haltForTraining(__('Practice: cash-box action simulated.'))) {
                    return;
                }

                abort_unless(auth()->user()->role->atLeast(Role::Manager), 403);
                abort_unless(MembershipSetting::current()->register_shifts_enabled, 403);
                abort_unless(array_key_exists((int) $data['event_id'], $this->instructorPayoutEventOptions(paid: true)), 403);
                abort_if(Cents::of($data['amount']) === 0, 422);

                $shift = $this->getOpenShift();
                abort_unless($shift, 404);

                app(RegisterShiftService::class)->recordInstructorPayout(
                    $shift,
                    Event::findOrFail($data['event_id']),
                    auth()->user(),
                    (float) $data['amount'],
                    $data['notes'],
                    correction: true,
                );

                Notification::make()->title(__('Correction recorded'))->success()->send();
            });
    }

    public function closeShiftAction(): Action
    {
        return Action::make('closeShift')
            ->label(__('Close Box'))
            // No drop yet: offer one for what the envelopes should hold, so
            // the final count is just what stays in the box.
            ->schema(fn (): array => [
                ...($this->undroppedCashCents() > 0 ? [
                    Placeholder::make('envelope_snapshot')
                        ->label(__('No drop recorded yet'))
                        ->content(fn (): string => MembershipSetting::current()->cash_envelope_reminder_enabled
                            ? $this->envelopeReminderBody($this->getOpenShift(), now())
                            : __('Cash to drop: :amount.', ['amount' => $this->formatCurrency(Cents::toFloat($this->undroppedCashCents()))])),
                    TextInput::make('drop_amount')
                        ->label(__('Drop before closing'))
                        ->helperText(__('Recorded as a drop first. Set to 0 to close without one.'))
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->default(fn (): float => Cents::toFloat($this->undroppedCashCents())),
                ] : []),
                TextInput::make('closing_count')
                    ->label(__('Final count'))
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->required(),
                Textarea::make('notes')
                    ->maxLength(255),
            ])
            ->visible(fn (): bool => (bool) $this->getOpenShift())
            ->action(function (array $data): void {
                if ($this->haltForTraining(__('Practice: cash-box action simulated.'))) {
                    return;
                }

                abort_unless(MembershipSetting::current()->register_shifts_enabled, 403);

                $shift = $this->getOpenShift();
                abort_unless($shift, 404);

                $service = app(RegisterShiftService::class);
                $dropAmount = (float) ($data['drop_amount'] ?? 0);

                $closed = DB::transaction(function () use ($service, $shift, $data, $dropAmount): RegisterShift {
                    // Re-checked: another register may have dropped since
                    // the modal opened, and that drop already took the cash.
                    if ($dropAmount > 0 && $service->totalDropsCents($shift) === 0) {
                        $service->recordDrop($shift, auth()->user(), $dropAmount, __('Envelopes at close'));
                    }

                    return $service->closeShift($shift, auth()->user(), (float) $data['closing_count'], $data['notes'] ?? null);
                });
                $variance = $service->varianceCents($closed) ?? 0;
                $label = $variance === 0 ? __('exact') : ($variance > 0 ? __('over') : __('short'));

                $notification = Notification::make()->title(__('Box closed — :amount :label', ['amount' => $this->formatCurrency(Cents::toFloat(abs($variance))), 'label' => $label]));

                // Opt-in house procedure (Feature Flags): any cash actually
                // collected gets sealed in an envelope labelled with the
                // category breakdown plus the event(s) it covers, for whoever
                // reconciles/deposits it later. Persistent (doesn't
                // auto-dismiss) since this is an instruction to act on, not
                // just a status toast.
                $body = [];
                $cashReceivedCents = $service->cashReceivedCents($closed);
                if ($cashReceivedCents > 0 && MembershipSetting::current()->cash_envelope_reminder_enabled) {
                    $body[] = $this->envelopeReminderBody($closed, $closed->closed_at);
                }

                // Never in the box, so never in an envelope -- listed so the
                // night's total can be checked against each app's own record.
                $electronic = $service->electronicByMethodCents($closed);
                if ($electronic !== []) {
                    $body[] = __('Taken electronically (not in the box or any envelope): :methods (total :total).', [
                        'methods' => collect($electronic)
                            ->map(fn (int $cents, string $label) => $label.' '.$this->formatCurrency(Cents::toFloat($cents)))
                            ->implode(' · '),
                        'total' => $this->formatCurrency(Cents::toFloat(array_sum($electronic))),
                    ]);
                }

                if ($body !== []) {
                    $notification->body(implode(' ', $body))->persistent();
                }

                $notification->success()->send();
            });
    }

    /**
     * The cash that would go into envelopes if the box closed now, with no
     * drop recorded yet: everything taken in over the opening float, after
     * instructor payouts and prepay cash held for later events. Zero once
     * any drop exists, since a drop is how the envelopes leave the box.
     */
    private function undroppedCashCents(): int
    {
        $shift = $this->getOpenShift();
        $service = app(RegisterShiftService::class);

        if (! $shift || $service->totalDropsCents($shift) !== 0) {
            return 0;
        }

        return max(0, $service->expectedClosingCountCents($shift) - Cents::of($shift->opening_count));
    }

    /**
     * @param  CarbonInterface  $date  labels an envelope with no event to name; the close time, or now() for the preview before closing
     */
    private function envelopeReminderBody(RegisterShift $shift, CarbonInterface $date): string
    {
        $service = app(RegisterShiftService::class);
        $breakdown = $service->cashRevenueBreakdownCents($shift);

        // Prepays for a later event go in that event's own envelope, so they
        // don't name this one.
        $eventLabels = Event::whereIn('id', Attendance::where('register_shift_id', $shift->id)->where('prepaid_ahead', false)->pluck('event_id'))
            ->orderBy('event_date')
            ->get()
            ->map(fn (Event $event) => "{$event->name} — {$event->event_date->isoFormat('ll')}")
            ->implode(', ');

        // No attendance row on this shift at all (e.g. only a standalone
        // subscription/misc-payment cash transaction) -- nothing to name, so
        // label the envelope with the date the box closed instead.
        if ($eventLabels === '') {
            $eventLabels = $date->isoFormat('ll');
        }

        $lines = [];
        // Subscription cash is its own envelope (below), like prepays, so
        // the night's envelope is entry plus other only.
        $tonightCents = $breakdown['event'] + $breakdown['other'];

        if ($tonightCents !== 0) {
            $lines[] = __('Cash collected — Entry: :entry · Other: :other (total :total). Make an envelope for :events with these amounts written on it.', [
                'entry' => $this->formatCurrency(Cents::toFloat($breakdown['event'])),
                'other' => $this->formatCurrency(Cents::toFloat($breakdown['other'])),
                'total' => $this->formatCurrency(Cents::toFloat($tonightCents)),
                'events' => $eventLabels,
            ]);
        }

        // Instructor cash already left the box, so the envelope holds less
        // than "total" -- say so, or the envelope looks short.
        $paidOutCents = $service->totalInstructorPayoutsCents($shift);

        if ($paidOutCents !== 0) {
            $lines[] = __('Also write: paid out to instructor :amount.', ['amount' => $this->formatCurrency(Cents::toFloat($paidOutCents))]);
        }

        if ($breakdown['subscription'] !== 0) {
            $lines[] = __('Subscription cash goes in its own envelope, labelled "Subscriptions — :date": :amount.', [
                'date' => $date->isoFormat('ll'),
                'amount' => $this->formatCurrency(Cents::toFloat($breakdown['subscription'])),
            ]);
        }

        $heldByEvent = $service->heldPrepayCashByEventCents($shift);

        if ($heldByEvent !== []) {
            $events = Event::whereIn('id', array_keys($heldByEvent))->get()->keyBy('id');
            $envelopes = collect($heldByEvent)
                ->map(fn (int $cents, int $eventId) => __(':event: :amount', [
                    'event' => $events[$eventId]->label(),
                    'amount' => $this->formatCurrency(Cents::toFloat($cents)),
                ]))
                ->implode('; ');

            $lines[] = __('Prepaid cash for later events goes in a separate envelope for each event, not in the box — :envelopes.', ['envelopes' => $envelopes]);
        }

        return implode(' ', $lines);
    }
}
