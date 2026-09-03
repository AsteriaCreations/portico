<?php

namespace App\Filament\Admin\Resources\Registers\Pages;

use App\Filament\Admin\Resources\Registers\RegisterResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRegister extends EditRecord
{
    protected static string $resource = RegisterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
