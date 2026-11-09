<?php

namespace App\Filament\Admin\Resources\RoleTasks\Pages;

use App\Filament\Admin\Resources\RoleTasks\RoleTaskResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRoleTasks extends ListRecords
{
    protected static string $resource = RoleTaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
