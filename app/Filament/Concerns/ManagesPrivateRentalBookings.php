<?php

namespace App\Filament\Concerns;

use App\Exceptions\PrivateRentalRefused;
use App\Models\MembershipSetting;
use App\Models\PrivateRental;
use App\Services\PrivateRentalQuote;
use App\Services\PrivateRentalService;
use App\Support\Cents;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * "Change" and "Cancel" for a private rental, shared by the Check-In Desk
 * (the renter's bookings on the member card) and the Private Rentals list.
 * Each caller says how to find the booking the button is for. The rules
 * are PrivateRentalService's; the book-private-rental gate is re-checked
 * server-side, and $isPractice (the desk's training mode) saves nothing.
 */
trait ManagesPrivateRentalBookings
{
    /**
     * @param  Closure(Action): ?PrivateRental  $resolveRental
     * @param  Closure(string): bool|null  $haltForPractice  given the practice message, true when nothing must be saved
     */
    protected function makeChangePrivateRentalAction(string $name, Closure $resolveRental, ?Closure $haltForPractice = null): Action
    {
        return Action::make($name)
            ->label('Change')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->size('sm')
            ->visible(fn (Action $action): bool => Gate::allows('book-private-rental')
                && ($rental = $resolveRental($action)) !== null
                && app(PrivateRentalService::class)->isChangeable($rental)
                && ! app(PrivateRentalService::class)->isPaid($rental))
            ->modalHeading(fn (Action $action): string => __('Change booking: :room', ['room' => $resolveRental($action)?->room->name]))
            ->modalSubmitActionLabel(__('Save changes'))
            ->fillForm(function (Action $action) use ($resolveRental): array {
                $rental = $resolveRental($action);

                return [
                    'date' => $rental?->starts_at->toDateString(),
                    'start_time' => $rental?->starts_at->format('H:i'),
                    'hours' => $rental?->hours,
                    'headcount' => $rental?->headcount,
                ];
            })
            ->schema(fn (Action $action): array => [
                DatePicker::make('date')
                    ->label(__('Date'))
                    ->required()
                    ->minDate(fn (): string => today()->toDateString())
                    ->live(),
                TimePicker::make('start_time')
                    ->label(__('Start time'))
                    ->required()
                    ->seconds(false)
                    ->live(onBlur: true),
                Select::make('hours')
                    ->label(__('Hours'))
                    ->options(function () use ($action, $resolveRental): array {
                        $room = $resolveRental($action)?->room;

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
                    ->content(fn (Get $get): HtmlString => $this->changedRentalSummary($resolveRental($action), $get('date'), $get('start_time'), $get('hours'), $get('headcount'))),
            ])
            ->action(function (array $data, Action $action) use ($resolveRental, $haltForPractice): void {
                abort_unless(Gate::allows('book-private-rental'), 403);

                $rental = $resolveRental($action);
                abort_unless($rental, 404);

                $startsAt = $this->privateRentalStart($data['date'], $data['start_time']);
                abort_unless($startsAt, 422);

                if ($haltForPractice && $haltForPractice(__('Practice: booking change simulated.'))) {
                    return;
                }

                try {
                    $rental = app(PrivateRentalService::class)->change($rental, $startsAt, (int) $data['hours'], (int) $data['headcount']);
                } catch (PrivateRentalRefused $refused) {
                    Notification::make()->title(__('Not changed'))->body($refused->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title(__('Booking changed'))
                    ->body(__(':room, :from–:to. Total :total.', [
                        'room' => $rental->room->name,
                        'from' => $rental->starts_at->isoFormat('llll'),
                        'to' => $rental->ends_at->isoFormat('LT'),
                        'total' => MembershipSetting::formatMoney((float) $rental->total),
                    ]))
                    ->success()
                    ->send();
            });
    }

    /**
     * @param  Closure(Action): ?PrivateRental  $resolveRental
     * @param  Closure(string): bool|null  $haltForPractice  given the practice message, true when nothing must be saved
     */
    protected function makeCancelPrivateRentalAction(string $name, Closure $resolveRental, ?Closure $haltForPractice = null): Action
    {
        return Action::make($name)
            ->label('Cancel booking')
            ->icon(Heroicon::OutlinedXMark)
            ->color('danger')
            ->size('sm')
            ->visible(fn (Action $action): bool => Gate::allows('book-private-rental')
                && ($rental = $resolveRental($action)) !== null
                && app(PrivateRentalService::class)->isChangeable($rental)
                && ! app(PrivateRentalService::class)->isPaid($rental))
            ->requiresConfirmation()
            ->modalHeading(__('Cancel this booking?'))
            ->modalDescription(fn (Action $action): string => __(':room, :when. The room is freed and the rental drops off the desk.', [
                'room' => $resolveRental($action)?->room->name,
                'when' => $resolveRental($action)?->starts_at->isoFormat('llll'),
            ]))
            ->modalSubmitActionLabel(__('Cancel booking'))
            ->action(function (Action $action) use ($resolveRental, $haltForPractice): void {
                abort_unless(Gate::allows('book-private-rental'), 403);

                $rental = $resolveRental($action);
                abort_unless($rental, 404);

                if ($haltForPractice && $haltForPractice(__('Practice: booking cancellation simulated.'))) {
                    return;
                }

                try {
                    app(PrivateRentalService::class)->cancel($rental, auth()->user());
                } catch (PrivateRentalRefused $refused) {
                    Notification::make()->title(__('Not cancelled'))->body($refused->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('Booking cancelled'))->success()->send();
            });
    }

    /**
     * The change dialog's running total: the booking's own rates with the
     * new hours and headcount, and any clash or refusal.
     */
    protected function changedRentalSummary(?PrivateRental $rental, mixed $date, mixed $startTime, mixed $hours, mixed $headcount): HtmlString
    {
        if (! $rental || blank($hours) || ! is_numeric($headcount) || (int) $headcount < 1) {
            return $this->privateRentalStatus([__('Choose the hours and how many people are coming to see the total.')]);
        }

        $service = app(PrivateRentalService::class);
        $quote = $service->requote($rental, (int) $hours, (int) $headcount);
        $lines = $this->privateRentalQuoteLines($quote);

        $startsAt = $this->privateRentalStart($date, $startTime);
        if ($startsAt) {
            $endsAt = $startsAt->copy()->addHours($quote->hours);
            $lines[] = __('Ends :time', ['time' => $endsAt->isoFormat('llll')]);

            if ($refusal = $service->refusalFor($rental->member, $startsAt)) {
                $lines[] = $refusal;
            } elseif ($conflict = $service->conflictFor($rental->room, $startsAt, $endsAt, $rental)) {
                $lines[] = __(':room is already booked :from–:to.', ['room' => $rental->room->name, 'from' => $conflict->starts_at->isoFormat('lll'), 'to' => $conflict->ends_at->isoFormat('LT')]);
            }
        }

        return $this->privateRentalStatus($lines);
    }

    /**
     * @return list<string>
     */
    protected function privateRentalQuoteLines(PrivateRentalQuote $quote): array
    {
        $money = fn (int $cents): string => MembershipSetting::formatMoney(Cents::toFloat($cents));

        return [
            __('Room: :rate × :hours h = :cost', ['rate' => $money($quote->hourlyRateCents), 'hours' => $quote->hours, 'cost' => $money($quote->roomCostCents)]),
            $quote->extraPeople > 0
                ? __('Extra people: :count × :each = :cost', ['count' => $quote->extraPeople, 'each' => $money($quote->perExtraPersonCents), 'cost' => $money($quote->extraPeopleCostCents)])
                : __('Extra people: none (the room includes :count)', ['count' => $quote->includedPeople]),
            __('Total: :total, paid at the start of the rental', ['total' => $money($quote->totalCents)]),
        ];
    }

    /**
     * A live region, so a screen reader hears the total change.
     *
     * @param  list<string>  $lines
     */
    protected function privateRentalStatus(array $lines): HtmlString
    {
        return new HtmlString('<div role="status">'.collect($lines)->map(fn (string $line): string => '<p>'.e($line).'</p>')->implode('').'</div>');
    }

    protected function privateRentalStart(mixed $date, mixed $startTime): ?Carbon
    {
        if (blank($date) || blank($startTime)) {
            return null;
        }

        try {
            return Carbon::parse(Carbon::parse($date)->toDateString().' '.$startTime);
        } catch (Throwable) {
            return null;
        }
    }
}
