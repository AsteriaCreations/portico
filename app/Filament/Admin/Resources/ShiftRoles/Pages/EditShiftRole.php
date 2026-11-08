<?php

namespace App\Filament\Admin\Resources\ShiftRoles\Pages;

use App\Filament\Admin\Resources\ShiftRoles\ShiftRoleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditShiftRole extends EditRecord
{
    protected static string $resource = ShiftRoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
