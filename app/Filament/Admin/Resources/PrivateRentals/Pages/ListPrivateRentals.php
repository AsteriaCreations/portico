<?php

namespace App\Filament\Admin\Resources\PrivateRentals\Pages;

use App\Filament\Admin\Resources\PrivateRentals\PrivateRentalResource;
use App\Filament\Concerns\ManagesPrivateRentalBookings;
use App\Models\PrivateRental;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

class ListPrivateRentals extends ListRecords
{
    use ManagesPrivateRentalBookings;

    protected static string $resource = PrivateRentalResource::class;

    public function table(Table $table): Table
    {
        return parent::table($table)->recordActions([
            $this->changeRowAction(),
            $this->cancelRowAction(),
        ]);
    }

    public function changeRowAction(): Action
    {
        return $this->makeChangePrivateRentalAction('change', fn (Action $action): ?PrivateRental => $action->getRecord());
    }

    public function cancelRowAction(): Action
    {
        return $this->makeCancelPrivateRentalAction('cancel', fn (Action $action): ?PrivateRental => $action->getRecord());
    }
}
