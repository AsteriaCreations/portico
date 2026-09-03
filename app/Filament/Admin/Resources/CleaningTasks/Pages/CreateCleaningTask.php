<?php

namespace App\Filament\Admin\Resources\CleaningTasks\Pages;

use App\Filament\Admin\Resources\CleaningTasks\CleaningTaskResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCleaningTask extends CreateRecord
{
    protected static string $resource = CleaningTaskResource::class;
}
