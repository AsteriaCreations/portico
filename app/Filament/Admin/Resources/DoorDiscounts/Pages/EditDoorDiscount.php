<?php

namespace App\Filament\Admin\Resources\DoorDiscounts\Pages;

use App\Filament\Admin\Resources\DoorDiscounts\DoorDiscountResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDoorDiscount extends EditRecord
{
    protected static string $resource = DoorDiscountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
