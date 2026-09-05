<?php

namespace App\Filament\Admin\Resources\PaperworkTypes;

use App\Filament\Admin\Resources\PaperworkTypes\Pages\CreatePaperworkType;
use App\Filament\Admin\Resources\PaperworkTypes\Pages\EditPaperworkType;
use App\Filament\Admin\Resources\PaperworkTypes\Pages\ListPaperworkTypes;
use App\Filament\Admin\Resources\PaperworkTypes\Schemas\PaperworkTypeForm;
use App\Filament\Admin\Resources\PaperworkTypes\Tables\PaperworkTypesTable;
use App\Models\PaperworkType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class PaperworkTypeResource extends Resource
{
    protected static ?string $model = PaperworkType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return PaperworkTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaperworkTypesTable::configure($table);
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
            'index' => ListPaperworkTypes::route('/'),
            'create' => CreatePaperworkType::route('/create'),
            'edit' => EditPaperworkType::route('/{record}/edit'),
        ];
    }
}
