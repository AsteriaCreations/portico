<?php

namespace App\Filament\Admin\Resources\ShowrunnerPayoutTiers\Pages;

use App\Filament\Admin\Resources\ShowrunnerPayoutTiers\ShowrunnerPayoutTierResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditShowrunnerPayoutTier extends EditRecord
{
    protected static string $resource = ShowrunnerPayoutTierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
