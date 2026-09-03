<?php

namespace App\Filament\Admin\Resources\CleaningTasks\Pages;

use App\Filament\Admin\Resources\CleaningTasks\CleaningTaskResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCleaningTasks extends ListRecords
{
    protected static string $resource = CleaningTaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
