<?php

namespace App\Filament\Admin\Resources\CleaningTasks\Pages;

use App\Filament\Admin\Resources\CleaningTasks\CleaningTaskResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCleaningTask extends EditRecord
{
    protected static string $resource = CleaningTaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
