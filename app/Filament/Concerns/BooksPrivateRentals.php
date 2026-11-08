<?php

namespace App\Filament\Concerns;

use App\Exceptions\PrivateRentalRefused;
use App\Models\Member;
use App\Models\MembershipSetting;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * The desk's "Book a private rental" for the selected member: pick a room,
 * a date, a start time and whole hours, and how many people are coming,
 * see the total, then confirm. Door+ once the club turns private rentals on
 * (book-private-rental gate). Who may book, the price and the overlap check
 * are all PrivateRentalService's; this only gathers the choices and shows
 * its answers. Payment is taken separately, at the start of the rental.
 */
trait BooksPrivateRentals
{
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
     * The modal's running total, or what's still missing / why it can't be
     * booked. A live region, so a screen reader hears the total change.
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
        $money = fn (int $cents): string => MembershipSetting::formatMoney(Cents::toFloat($cents));

        $lines = [
            __('Room: :rate × :hours h = :cost', ['rate' => $money(Cents::of($room->hourly_rate)), 'hours' => $quote->hours, 'cost' => $money($quote->roomCostCents)]),
            $quote->extraPeople > 0
                ? __('Extra people: :count × :each = :cost', ['count' => $quote->extraPeople, 'each' => $money($quote->perExtraPersonCents), 'cost' => $money($quote->extraPeopleCostCents)])
                : __('Extra people: none (the room includes :count)', ['count' => $room->included_people]),
            __('Total: :total, paid at the start of the rental', ['total' => $money($quote->totalCents)]),
        ];

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
     * @param  list<string>  $lines
     */
    private function privateRentalStatus(array $lines): HtmlString
    {
        return new HtmlString('<div role="status">'.collect($lines)->map(fn (string $line): string => '<p>'.e($line).'</p>')->implode('').'</div>');
    }

    private function privateRentalStart(mixed $date, mixed $startTime): ?Carbon
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

    abstract public function getSelectedMember(): ?Member;
}
