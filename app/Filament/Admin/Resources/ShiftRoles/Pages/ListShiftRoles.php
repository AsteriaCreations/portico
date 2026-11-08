<?php

namespace App\Filament\Admin\Resources\ShiftRoles\Pages;

use App\Filament\Admin\Resources\ShiftRoles\ShiftRoleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListShiftRoles extends ListRecords
{
    protected static string $resource = ShiftRoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
