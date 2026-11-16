<?php

namespace App\Filament\Admin\Resources\EventTimelineCategories\Pages;

use App\Filament\Admin\Resources\EventTimelineCategories\EventTimelineCategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEventTimelineCategory extends EditRecord
{
    protected static string $resource = EventTimelineCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
