<?php

namespace App\Filament\Admin\Resources\ShowrunnerPayoutTiers\Pages;

use App\Filament\Admin\Resources\ShowrunnerPayoutTiers\ShowrunnerPayoutTierResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListShowrunnerPayoutTiers extends ListRecords
{
    protected static string $resource = ShowrunnerPayoutTierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
