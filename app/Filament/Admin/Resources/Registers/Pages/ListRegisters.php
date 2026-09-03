<?php

namespace App\Filament\Admin\Resources\Registers\Pages;

use App\Filament\Admin\Resources\Registers\RegisterResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRegisters extends ListRecords
{
    protected static string $resource = RegisterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
