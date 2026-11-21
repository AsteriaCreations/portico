<?php

namespace App\Filament\Admin\Resources\DoorDiscounts\Pages;

use App\Filament\Admin\Resources\DoorDiscounts\DoorDiscountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDoorDiscounts extends ListRecords
{
    protected static string $resource = DoorDiscountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
