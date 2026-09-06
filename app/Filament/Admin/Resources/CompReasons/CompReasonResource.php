<?php

namespace App\Filament\Admin\Resources\CompReasons;

use App\Filament\Admin\Resources\CompReasons\Pages\CreateCompReason;
use App\Filament\Admin\Resources\CompReasons\Pages\EditCompReason;
use App\Filament\Admin\Resources\CompReasons\Pages\ListCompReasons;
use App\Filament\Admin\Resources\CompReasons\Schemas\CompReasonForm;
use App\Filament\Admin\Resources\CompReasons\Tables\CompReasonsTable;
use App\Models\CompReason;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CompReasonResource extends Resource
{
    protected static ?string $model = CompReason::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|UnitEnum|null $navigationGroup = 'Desk & Money';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return CompReasonForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CompReasonsTable::configure($table);
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
            'index' => ListCompReasons::route('/'),
            'create' => CreateCompReason::route('/create'),
            'edit' => EditCompReason::route('/{record}/edit'),
        ];
    }
}
