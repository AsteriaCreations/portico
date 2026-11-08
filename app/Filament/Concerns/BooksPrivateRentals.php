<?php

namespace App\Filament\Concerns;

use App\Exceptions\PrivateRentalRefused;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\PrivateRental;
use App\Models\Room;
use App\Services\PrivateRentalService;
use App\Support\Cents;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

/**
 * The desk's "Book a private rental" for the selected member: pick a room,
 * a date, a start time and whole hours, and how many people are coming,
 * see the total, then confirm. Below it, the member's upcoming bookings,
 * each with Change and Cancel. Door+ once the club turns private rentals on
 * (book-private-rental gate). Who may book, the price and the overlap check
 * are all PrivateRentalService's; this only gathers the choices and shows
 * its answers. Payment is taken separately, at the start of the rental.
 */
trait BooksPrivateRentals
{
    use ManagesPrivateRentalBookings;

    public function bookPrivateRentalAction(): Action
    {
        return Action::make('bookPrivateRental')
            ->label('Book a private rental')
            ->icon(Heroicon::OutlinedKey)
            ->color('gray')
            ->visible(fn (): bool => $this->getSelectedMember() !== null && Gate::allows('book-private-rental'))
            ->modalHeading(fn (): string => __('Book a private rental for :name', ['name' => $this->getSelectedMember()?->displayName()]))
            ->modalSubmitActionLabel(__('Book'))
            ->schema([
                Select::make('room_id')
                    ->label(__('Room'))
                    ->options(fn (): array => Room::where('active', true)->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all())
                    ->required()
                    ->live(),
                DatePicker::make('date')
                    ->label(__('Date'))
                    ->required()
                    ->default(fn (): string => today()->toDateString())
                    ->minDate(fn (): string => today()->toDateString())
                    ->live(),
                TimePicker::make('start_time')
                    ->label(__('Start time'))
                    ->required()
                    ->seconds(false)
                    ->live(onBlur: true),
                Select::make('hours')
                    ->label(__('Hours'))
                    ->options(function (Get $get): array {
                        $room = Room::find($get('room_id'));

                        return $room ? array_combine(range($room->min_hours, $room->max_hours), range($room->min_hours, $room->max_hours)) : [];
                    })
                    ->required()
                    ->live(),
                TextInput::make('headcount')
                    ->label(__('People coming'))
                    ->helperText(__('Everyone in the group, the renter included.'))
                    ->required()
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(1000)
                    ->live(onBlur: true),
                Placeholder::make('quote')
                    ->label(__('Total'))
                    ->content(fn (Get $get): HtmlString => $this->privateRentalQuoteSummary($get('room_id'), $get('date'), $get('start_time'), $get('hours'), $get('headcount'))),
            ])
            ->action(function (array $data, Action $action): void {
                abort_unless(Gate::allows('book-private-rental'), 403);

                $member = $this->getSelectedMember();
                abort_unless($member, 404);

                $room = Room::findOrFail($data['room_id']);
                $startsAt = $this->privateRentalStart($data['date'], $data['start_time']);
                abort_unless($startsAt, 422);

                $hours = (int) $data['hours'];
                $headcount = (int) $data['headcount'];
                $service = app(PrivateRentalService::class);

                if ($this->trainingMode) {
                    $refusal = $service->refusalFor($member, $startsAt)
                        ?? ($service->conflictFor($room, $startsAt, $startsAt->copy()->addHours($hours)) ? __(':room is already booked then.', ['room' => $room->name]) : null);

                    $this->haltForTraining($refusal ?? __('Practice: :room booked for :total.', [
                        'room' => $room->name,
                        'total' => MembershipSetting::formatMoney(Cents::toFloat($service->quote($room, $hours, $headcount)->totalCents)),
                    ]));

                    return;
                }

                try {
                    $rental = $service->book($member, $room, $startsAt, $hours, $headcount, auth()->user());
                } catch (PrivateRentalRefused $refused) {
                    Notification::make()->title(__('Not booked'))->body($refused->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title(__('Private rental booked'))
                    ->body(__(':room, :from–:to, for :name. Total :total, paid at the start of the rental.', [
                        'room' => $room->name,
                        'from' => $rental->starts_at->isoFormat('llll'),
                        'to' => $rental->ends_at->isoFormat('LT'),
                        'name' => $member->displayName(),
                        'total' => MembershipSetting::formatMoney((float) $rental->total),
                    ]))
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    /**
     * Takes the booking's total at the start of the rental, through this
     * desk's register like any desk sale: cash only while a box is open,
     * with the method's transaction fee added.
     */
    public function collectPrivateRentalPaymentAction(): Action
    {
        return Action::make('collectPrivateRentalPayment')
            ->label('Collect payment')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->size('sm')
            ->visible(fn (Action $action): bool => Gate::allows('book-private-rental')
                && ($rental = $this->deskPrivateRental($action)) !== null
                && app(PrivateRentalService::class)->canCollectPayment($rental))
            ->modalHeading(fn (Action $action): string => __('Collect payment: :room', ['room' => $this->deskPrivateRental($action)?->room->name]))
            ->modalDescription(fn (Action $action): string => __('Total :total for the room and :count people. A payment method\'s fee is added on top.', [
                'total' => MembershipSetting::formatMoney((float) $this->deskPrivateRental($action)?->total),
                'count' => $this->deskPrivateRental($action)?->headcount,
            ]))
            ->modalSubmitActionLabel(__('Record payment'))
            ->schema(fn (): array => [
                Select::make('payment_method')
                    ->label(__('Payment method'))
                    ->options($this->paymentMethodOptions($this->getOpenShift()))
                    ->required()
                    ->live()
                    ->helperText(fn (Get $get): ?string => $this->paymentMethodHelperText($this->getOpenShift(), $get('payment_method'))),
            ])
            ->action(function (array $data, Action $action): void {
                abort_unless(Gate::allows('book-private-rental'), 403);

                $rental = $this->deskPrivateRental($action);
                abort_unless($rental, 404);

                if ($this->haltForTraining(__('Practice: rental payment simulated.'))) {
                    return;
                }

                try {
                    $payment = app(PrivateRentalService::class)->collectPayment($rental, $data['payment_method'], $this->getOpenShift(), auth()->user());
                } catch (PrivateRentalRefused $refused) {
                    Notification::make()->title(__('Not recorded'))->body($refused->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title(__('Rental paid — :amount', ['amount' => MembershipSetting::formatMoney((float) $payment->amount)]))
                    ->success()
                    ->send();
            });
    }

    /**
     * Manager+: cancel a paid booking and give back everything paid, out of
     * this desk's box when the refund is cash. Needs a reason.
     */
    public function refundPrivateRentalAction(): Action
    {
        return Action::make('refundPrivateRental')
            ->label('Cancel and refund')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->size('sm')
            ->visible(fn (Action $action): bool => Gate::allows('refund-private-rental')
                && ($rental = $this->deskPrivateRental($action)) !== null
                && app(PrivateRentalService::class)->isChangeable($rental)
                && app(PrivateRentalService::class)->isPaid($rental))
            ->modalHeading(__('Cancel and refund this booking?'))
            ->modalDescription(fn (Action $action): string => __('Gives back :amount and frees the room. The rental drops off the desk.', [
                'amount' => ($rental = $this->deskPrivateRental($action))
                    ? MembershipSetting::formatMoney(Cents::toFloat(app(PrivateRentalService::class)->netPaidCents($rental)))
                    : '',
            ]))
            ->modalSubmitActionLabel(__('Cancel and refund'))
            ->fillForm(fn (Action $action): array => [
                'payment_method' => $this->deskPrivateRental($action)?->payments()->where('amount', '>', 0)->latest('id')->value('payment_method'),
            ])
            ->schema(fn (): array => [
                Select::make('payment_method')
                    ->label(__('Refund by'))
                    ->options($this->paymentMethodOptions($this->getOpenShift()))
                    ->required()
                    ->helperText(fn (): ?string => $this->paymentMethodHelperText($this->getOpenShift(), null)),
                TextInput::make('reason')
                    ->label(__('Reason'))
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (array $data, Action $action): void {
                abort_unless(Gate::allows('refund-private-rental'), 403);

                $rental = $this->deskPrivateRental($action);
                abort_unless($rental, 404);

                if ($this->haltForTraining(__('Practice: refund simulated.'))) {
                    return;
                }

                try {
                    $refund = app(PrivateRentalService::class)->refundAndCancel($rental, $data['payment_method'], $this->getOpenShift(), $data['reason'], auth()->user());
                } catch (PrivateRentalRefused $refused) {
                    Notification::make()->title(__('Not refunded'))->body($refused->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title(__('Booking cancelled — refunded :amount', ['amount' => MembershipSetting::formatMoney(abs((float) $refund->amount))]))
                    ->success()
                    ->send();
            });
    }

    public function changePrivateRentalAction(): Action
    {
        return $this->makeChangePrivateRentalAction('changePrivateRental', fn (Action $action): ?PrivateRental => $this->deskPrivateRental($action), fn (string $message): bool => $this->haltForTraining($message));
    }

    public function cancelPrivateRentalAction(): Action
    {
        return $this->makeCancelPrivateRentalAction('cancelPrivateRental', fn (Action $action): ?PrivateRental => $this->deskPrivateRental($action), fn (string $message): bool => $this->haltForTraining($message));
    }

    /**
     * The selected member's bookings that haven't ended or been cancelled,
     * soonest first, for the member card.
     *
     * @return Collection<int, PrivateRental>
     */
    public function upcomingPrivateRentals(): Collection
    {
        $member = $this->getSelectedMember();

        if (! $member || ! Gate::allows('book-private-rental')) {
            return collect();
        }

        return PrivateRental::with('room')
            ->withSum('payments', 'amount')
            ->notCancelled()
            ->where('member_id', $member->id)
            ->where('ends_at', '>', now())
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * When the desk's event is a private rental: how many of its covered
     * spots are used, and what the next person pays. Null otherwise.
     */
    public function selectedEventRentalSpotsLine(): ?string
    {
        $rental = $this->getSelectedEvent()?->privateRental;

        if ($rental === null || $rental->cancelled_at !== null) {
            return null;
        }

        $taken = $rental->spotsTaken();
        $covered = $rental->coveredSpots();

        return $taken < $covered
            ? __('Private rental: :taken of :covered covered spots used. The next person is covered by the rental.', ['taken' => $taken, 'covered' => $covered])
            : __('Private rental: all :covered covered spots used. Each extra person pays :fee.', ['covered' => $covered, 'fee' => MembershipSetting::formatMoney((float) $rental->event->entry_fee)]);
    }

    /**
     * The modal's running total, or what's still missing / why it can't be
     * booked.
     */
    protected function privateRentalQuoteSummary(mixed $roomId, mixed $date, mixed $startTime, mixed $hours, mixed $headcount): HtmlString
    {
        $room = filled($roomId) ? Room::find($roomId) : null;
        $member = $this->getSelectedMember();

        if (! $room || ! $member || blank($hours) || ! is_numeric($headcount) || (int) $headcount < 1) {
            return $this->privateRentalStatus([__('Choose a room, hours and how many people are coming to see the total.')]);
        }

        $service = app(PrivateRentalService::class);
        $quote = $service->quote($room, (int) $hours, (int) $headcount);
        $lines = $this->privateRentalQuoteLines($quote);

        $startsAt = $this->privateRentalStart($date, $startTime);
        if ($startsAt) {
            $endsAt = $startsAt->copy()->addHours($quote->hours);
            $lines[] = __('Ends :time', ['time' => $endsAt->isoFormat('llll')]);

            if ($refusal = $service->refusalFor($member, $startsAt)) {
                $lines[] = $refusal;
            } elseif ($conflict = $service->conflictFor($room, $startsAt, $endsAt)) {
                $lines[] = __(':room is already booked :from–:to.', ['room' => $room->name, 'from' => $conflict->starts_at->isoFormat('lll'), 'to' => $conflict->ends_at->isoFormat('LT')]);
            }
        }

        return $this->privateRentalStatus($lines);
    }

    /**
     * The booking a member-card button is for, only ever one of the
     * selected member's, so a forged id can't reach anyone else's.
     */
    private function deskPrivateRental(Action $action): ?PrivateRental
    {
        $member = $this->getSelectedMember();
        $id = $action->getArguments()['rental'] ?? null;

        return $member && $id ? PrivateRental::where('member_id', $member->id)->find($id) : null;
    }

    abstract public function getSelectedMember(): ?Member;

    abstract public function getSelectedEvent(): ?Event;
}
