<?php

namespace App\Filament\Admin\Resources\EventTypes\Pages;

use App\Filament\Admin\Resources\EventTypes\EventTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEventType extends EditRecord
{
    protected static string $resource = EventTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
