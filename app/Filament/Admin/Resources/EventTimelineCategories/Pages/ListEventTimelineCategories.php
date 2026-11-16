<?php

namespace App\Filament\Admin\Resources\EventTimelineCategories\Pages;

use App\Filament\Admin\Resources\EventTimelineCategories\EventTimelineCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEventTimelineCategories extends ListRecords
{
    protected static string $resource = EventTimelineCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
