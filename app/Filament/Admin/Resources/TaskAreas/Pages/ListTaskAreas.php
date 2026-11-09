<?php

namespace App\Filament\Admin\Resources\TaskAreas\Pages;

use App\Filament\Admin\Resources\TaskAreas\TaskAreaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTaskAreas extends ListRecords
{
    protected static string $resource = TaskAreaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
