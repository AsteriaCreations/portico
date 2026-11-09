<?php

namespace App\Filament\Admin\Resources\TaskAreas\Pages;

use App\Filament\Admin\Resources\TaskAreas\TaskAreaResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTaskArea extends EditRecord
{
    protected static string $resource = TaskAreaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
