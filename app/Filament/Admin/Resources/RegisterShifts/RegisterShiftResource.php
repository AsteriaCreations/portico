<?php

namespace App\Filament\Admin\Resources\RegisterShifts;

use App\Filament\Admin\Resources\RegisterShifts\Pages\ListRegisterShifts;
use App\Filament\Admin\Resources\RegisterShifts\Pages\ViewRegisterShift;
use App\Filament\Admin\Resources\RegisterShifts\RelationManagers\MiscellaneousPaymentsRelationManager;
use App\Filament\Admin\Resources\RegisterShifts\Tables\RegisterShiftsTable;
use App\Models\RegisterShift;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

// List + view only: a shift is opened/closed only through CheckIn's page
// actions via RegisterShiftService, never a generic Filament form (see
// RegisterShiftPolicy — create/update/delete are hard-false for everyone).
// The view page exists purely to host MiscellaneousPaymentsRelationManager's
// itemized ledger, same shape as VoucherResource otherwise.
class RegisterShiftResource extends Resource
{
    protected static ?string $model = RegisterShift::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?string $navigationLabel = 'Register Shifts';

    public static function table(Table $table): Table
    {
        return RegisterShiftsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            MiscellaneousPaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRegisterShifts::route('/'),
            'view' => ViewRegisterShift::route('/{record}'),
        ];
    }
}
