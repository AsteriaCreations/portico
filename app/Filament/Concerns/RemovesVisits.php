<?php

namespace App\Filament\Concerns;

use App\Models\Attendance;
use App\Services\VisitRemovalService;
use Filament\Actions\Action;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * The "Remove" row action and the $0-only bulk delete, shared by every
 * relation manager that lists attendance rows. The rules live in
 * VisitRemovalService; this only asks for a reason on a paid visit and
 * re-checks read-only (an archived event's tabs) server-side.
 */
trait RemovesVisits
{
    protected function removeVisitAction(): Action
    {
        return Action::make('removeVisit')
            ->label('Remove')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (Attendance $record): bool => ! $this->isReadOnly()
                && app(VisitRemovalService::class)->refusalReason($record, auth()->user()) === null)
            ->modalDescription(fn (Attendance $record): string => app(VisitRemovalService::class)->refundLabel($record)
                ?? __('Nothing was paid for this visit, so it\'s simply removed.'))
            ->schema(fn (Attendance $record): array => app(VisitRemovalService::class)->isPaid($record) ? [
                TextInput::make('reason')
                    ->label('Reason')
                    ->helperText(__('Removing a paid visit is recorded, and the Owner is notified.'))
                    ->required()
                    ->maxLength(255),
            ] : [])
            ->action(function (Attendance $record, array $data): void {
                abort_if($this->isReadOnly(), 403);

                app(VisitRemovalService::class)->remove($record, auth()->user(), $data['reason'] ?? null);

                Notification::make()->title(__('Visit removed'))->success()->send();
            });
    }

    /**
     * Bulk delete stays for clearing unpaid rows (e.g. a prepay list set up
     * by mistake). AttendancePolicy::delete() refuses a paid visit or one
     * other records point at, so those are skipped, never deleted.
     */
    protected function deleteUnpaidVisitsBulkAction(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->label('Delete unpaid')
            ->modalDescription(__('Deletes the selected visits that have nothing paid. Paid visits are skipped: remove those one at a time, with a reason.'))
            ->authorizeIndividualRecords('delete');
    }
}
