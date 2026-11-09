<?php

namespace App\Filament\Admin\Resources\RoleTasks\Pages;

use App\Filament\Admin\Resources\RoleTasks\RoleTaskResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRoleTask extends EditRecord
{
    protected static string $resource = RoleTaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
