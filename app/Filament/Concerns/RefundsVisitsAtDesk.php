<?php

namespace App\Filament\Concerns;

use App\Services\DeskRefundService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * The Check-In Desk's "Refund visit", soon after check-in. The rules and the
 * write live in DeskRefundService; this only shows what to hand back,
 * collects the reason, and re-checks the gate.
 *
 * Relies on the host page for getExistingAttendance() and haltForTraining().
 */
trait RefundsVisitsAtDesk
{
    public function refundVisitAction(): Action
    {
        $attendance = $this->getExistingAttendance();
        $service = app(DeskRefundService::class);

        return Action::make('refundVisit')
            ->label(__('Refund visit'))
            ->icon(Heroicon::OutlinedReceiptRefund)
            ->color('danger')
            ->visible(fn (): bool => $attendance !== null
                && Gate::allows('refund-visit-at-desk')
                && $service->refusalReason($attendance, auth()->user()) === null)
            ->modalHeading(__('Refund this visit?'))
            ->modalDescription(fn (): string => $attendance ? $service->refundLabel($attendance) : '')
            ->modalSubmitActionLabel(__('Refund'))
            ->schema([
                TextInput::make('reason')
                    ->label(__('Reason'))
                    ->required()
                    ->maxLength(200),
            ])
            ->action(function (array $data): void {
                if ($this->haltForTraining(__('Practice: refund simulated.'))) {
                    return;
                }

                abort_unless(Gate::allows('refund-visit-at-desk'), 403);

                $attendance = $this->getExistingAttendance();
                abort_unless($attendance, 404);

                app(DeskRefundService::class)->refund($attendance, auth()->user(), $data['reason']);

                Notification::make()
                    ->title(__('Visit refunded'))
                    ->body(__('They\'re marked departed. Hand the money back now.'))
                    ->success()
                    ->persistent()
                    ->send();
            });
    }
}
