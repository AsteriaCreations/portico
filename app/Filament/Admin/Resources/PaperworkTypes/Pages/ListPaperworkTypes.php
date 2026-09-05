<?php

namespace App\Filament\Admin\Resources\PaperworkTypes\Pages;

use App\Filament\Admin\Resources\PaperworkTypes\PaperworkTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPaperworkTypes extends ListRecords
{
    protected static string $resource = PaperworkTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
