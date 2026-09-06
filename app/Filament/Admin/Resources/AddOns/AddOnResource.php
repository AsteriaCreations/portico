<?php

namespace App\Filament\Admin\Resources\AddOns;

use App\Filament\Admin\Resources\AddOns\Pages\CreateAddOn;
use App\Filament\Admin\Resources\AddOns\Pages\EditAddOn;
use App\Filament\Admin\Resources\AddOns\Pages\ListAddOns;
use App\Filament\Admin\Resources\AddOns\Schemas\AddOnForm;
use App\Filament\Admin\Resources\AddOns\Tables\AddOnsTable;
use App\Models\AddOn;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AddOnResource extends Resource
{
    protected static ?string $model = AddOn::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlusCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Desk & Money';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return AddOnForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AddOnsTable::configure($table);
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
            'index' => ListAddOns::route('/'),
            'create' => CreateAddOn::route('/create'),
            'edit' => EditAddOn::route('/{record}/edit'),
        ];
    }
}
