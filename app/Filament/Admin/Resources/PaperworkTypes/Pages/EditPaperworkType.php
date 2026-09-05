<?php

namespace App\Filament\Admin\Resources\PaperworkTypes\Pages;

use App\Filament\Admin\Resources\PaperworkTypes\PaperworkTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPaperworkType extends EditRecord
{
    protected static string $resource = PaperworkTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
