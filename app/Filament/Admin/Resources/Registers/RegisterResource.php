<?php

namespace App\Filament\Admin\Resources\Registers;

use App\Filament\Admin\Resources\Registers\Pages\CreateRegister;
use App\Filament\Admin\Resources\Registers\Pages\EditRegister;
use App\Filament\Admin\Resources\Registers\Pages\ListRegisters;
use App\Filament\Admin\Resources\Registers\Schemas\RegisterForm;
use App\Filament\Admin\Resources\Registers\Tables\RegistersTable;
use App\Models\Register;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RegisterResource extends Resource
{
    protected static ?string $model = Register::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Desk & Money';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return RegisterForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RegistersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRegisters::route('/'),
            'create' => CreateRegister::route('/create'),
            'edit' => EditRegister::route('/{record}/edit'),
        ];
    }
}
