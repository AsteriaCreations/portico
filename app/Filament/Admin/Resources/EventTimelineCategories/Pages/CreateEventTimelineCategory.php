<?php

namespace App\Filament\Admin\Resources\EventTimelineCategories\Pages;

use App\Filament\Admin\Resources\EventTimelineCategories\EventTimelineCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEventTimelineCategory extends CreateRecord
{
    protected static string $resource = EventTimelineCategoryResource::class;
}
