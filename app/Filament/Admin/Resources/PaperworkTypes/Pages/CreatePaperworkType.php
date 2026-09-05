<?php

namespace App\Filament\Admin\Resources\PaperworkTypes\Pages;

use App\Filament\Admin\Resources\PaperworkTypes\PaperworkTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePaperworkType extends CreateRecord
{
    protected static string $resource = PaperworkTypeResource::class;
}
