<?php

namespace App\Filament\Concerns;

use App\Services\PaymentMethodCorrectionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * The Check-In Desk's "Correct payment method", for a visit recorded as paid
 * the wrong way. The rules and the write live in
 * PaymentMethodCorrectionService; this only shows the choices, collects the
 * reason, and re-checks the gate.
 *
 * Relies on the host page for getExistingAttendance() and haltForTraining().
 */
trait CorrectsPaymentMethodAtDesk
{
    public function correctPaymentMethodAction(): Action
    {
        $attendance = $this->getExistingAttendance();
        $service = app(PaymentMethodCorrectionService::class);

        return Action::make('correctPaymentMethod')
            ->label(__('Correct payment method'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('gray')
            ->visible(fn (): bool => $attendance !== null
                && Gate::allows('correct-visit-payment-method')
                && $service->refusalReason($attendance, auth()->user()) === null)
            ->modalHeading(__('Correct the payment method?'))
            ->modalDescription(fn (): string => $attendance ? $service->summaryLabel($attendance) : '')
            ->modalSubmitActionLabel(__('Correct'))
            ->schema([
                Select::make('payment_method')
                    ->label(__('Really paid by'))
                    ->options(fn (): array => $attendance ? $service->methodOptions($attendance) : [])
                    ->required(),
                TextInput::make('reason')
                    ->label(__('Reason'))
                    ->required()
                    ->maxLength(200),
            ])
            ->action(function (array $data): void {
                if ($this->haltForTraining(__('Practice: payment method correction simulated.'))) {
                    return;
                }

                abort_unless(Gate::allows('correct-visit-payment-method'), 403);

                $attendance = $this->getExistingAttendance();
                abort_unless($attendance, 404);

                $correction = app(PaymentMethodCorrectionService::class)->correct($attendance, auth()->user(), $data['payment_method'], $data['reason']);

                Notification::make()
                    ->title(__('Payment method corrected'))
                    ->body(__('Now recorded as paid by :method. No money changes hands.', [
                        'method' => app(PaymentMethodCorrectionService::class)->methodLabel($correction->new_payment_method),
                    ]))
                    ->success()
                    ->send();
            });
    }
}
