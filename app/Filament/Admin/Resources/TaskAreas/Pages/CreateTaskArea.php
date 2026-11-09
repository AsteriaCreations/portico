<?php

namespace App\Filament\Admin\Resources\TaskAreas\Pages;

use App\Filament\Admin\Resources\TaskAreas\TaskAreaResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTaskArea extends CreateRecord
{
    protected static string $resource = TaskAreaResource::class;
}
