<?php

namespace App\Filament\Admin\Resources\CompReasons\Pages;

use App\Filament\Admin\Resources\CompReasons\CompReasonResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCompReason extends EditRecord
{
    protected static string $resource = CompReasonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
