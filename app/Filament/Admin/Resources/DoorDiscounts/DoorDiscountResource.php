<?php

namespace App\Filament\Admin\Resources\DoorDiscounts;

use App\Filament\Admin\Resources\DoorDiscounts\Pages\CreateDoorDiscount;
use App\Filament\Admin\Resources\DoorDiscounts\Pages\EditDoorDiscount;
use App\Filament\Admin\Resources\DoorDiscounts\Pages\ListDoorDiscounts;
use App\Filament\Admin\Resources\DoorDiscounts\Schemas\DoorDiscountForm;
use App\Filament\Admin\Resources\DoorDiscounts\Tables\DoorDiscountsTable;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Models\DoorDiscount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DoorDiscountResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = DoorDiscount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Desk & Money';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return DoorDiscountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DoorDiscountsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDoorDiscounts::route('/'),
            'create' => CreateDoorDiscount::route('/create'),
            'edit' => EditDoorDiscount::route('/{record}/edit'),
        ];
    }
}
