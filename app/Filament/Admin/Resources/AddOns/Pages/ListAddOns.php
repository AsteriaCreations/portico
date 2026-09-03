<?php

namespace App\Filament\Admin\Resources\AddOns\Pages;

use App\Filament\Admin\Resources\AddOns\AddOnResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAddOns extends ListRecords
{
    protected static string $resource = AddOnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
