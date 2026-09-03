<?php

namespace App\Filament\Admin\Resources\EventTypes\Pages;

use App\Filament\Admin\Resources\EventTypes\EventTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEventType extends CreateRecord
{
    protected static string $resource = EventTypeResource::class;
}
