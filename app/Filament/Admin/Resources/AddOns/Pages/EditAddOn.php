<?php

namespace App\Filament\Admin\Resources\AddOns\Pages;

use App\Filament\Admin\Resources\AddOns\AddOnResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAddOn extends EditRecord
{
    protected static string $resource = AddOnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
