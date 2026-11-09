<?php

namespace App\Filament\Admin\Resources\ShiftRoles\Pages;

use App\Filament\Admin\Resources\ShiftRoles\ShiftRoleResource;
use App\Models\ShiftRole;
use App\Services\ShiftSlotService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

class EditShiftRole extends EditRecord
{
    protected static string $resource = ShiftRoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->removeUnstaffedUpcomingShiftsAction(),
            DeleteAction::make(),
        ];
    }

    /**
     * For a role that's been turned off: clear its upcoming shifts nobody
     * is scheduled on, so they stop showing on Staffing and the schedule.
     * Shifts with people on them stay (ShiftSlotService).
     */
    protected function removeUnstaffedUpcomingShiftsAction(): Action
    {
        return Action::make('removeUnstaffedUpcomingShifts')
            ->label(__('Remove unstaffed upcoming shifts'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (): bool => $this->canRemoveUpcoming())
            ->requiresConfirmation()
            ->modalDescription(function (): string {
                $upcoming = app(ShiftSlotService::class)->upcomingShiftsFor($this->getRecord());
                $unstaffed = $upcoming['shifts'] - $upcoming['staffed'];

                return trim(trans_choice('Removes :count upcoming :role shift that nobody is scheduled on, with any availability given for it.|Removes :count upcoming :role shifts that nobody is scheduled on, with any availability given for them.', $unstaffed, ['role' => $this->getRecord()->name])
                    .' '.($upcoming['staffed'] > 0 ? trans_choice(':count shift with people scheduled stays; take them off on the Staff schedule first if it should go too.|:count shifts with people scheduled stay; take them off on the Staff schedule first if they should go too.', $upcoming['staffed']) : ''));
            })
            ->action(function (): void {
                abort_unless($this->canRemoveUpcoming(), 403);

                $removed = app(ShiftSlotService::class)->removeUnstaffedUpcomingShifts($this->getRecord());

                Notification::make()
                    ->title(trans_choice('Removed :count shift|Removed :count shifts', $removed))
                    ->success()
                    ->send();
            });
    }

    protected function canRemoveUpcoming(): bool
    {
        /** @var ShiftRole $role */
        $role = $this->getRecord();

        if ($role->active || ! Gate::allows('update', $role)) {
            return false;
        }

        $upcoming = app(ShiftSlotService::class)->upcomingShiftsFor($role);

        return $upcoming['shifts'] > $upcoming['staffed'];
    }
}
