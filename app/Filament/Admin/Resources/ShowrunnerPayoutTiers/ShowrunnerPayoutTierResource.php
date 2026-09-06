<?php

namespace App\Filament\Admin\Resources\ShowrunnerPayoutTiers;

use App\Filament\Admin\Resources\ShowrunnerPayoutTiers\Pages\CreateShowrunnerPayoutTier;
use App\Filament\Admin\Resources\ShowrunnerPayoutTiers\Pages\EditShowrunnerPayoutTier;
use App\Filament\Admin\Resources\ShowrunnerPayoutTiers\Pages\ListShowrunnerPayoutTiers;
use App\Filament\Admin\Resources\ShowrunnerPayoutTiers\Schemas\ShowrunnerPayoutTierForm;
use App\Filament\Admin\Resources\ShowrunnerPayoutTiers\Tables\ShowrunnerPayoutTiersTable;
use App\Models\ShowrunnerPayoutTier;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ShowrunnerPayoutTierResource extends Resource
{
    protected static ?string $model = ShowrunnerPayoutTier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Members & Events';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Showrunner Payout Tiers';

    public static function form(Schema $schema): Schema
    {
        return ShowrunnerPayoutTierForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ShowrunnerPayoutTiersTable::configure($table);
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
            'index' => ListShowrunnerPayoutTiers::route('/'),
            'create' => CreateShowrunnerPayoutTier::route('/create'),
            'edit' => EditShowrunnerPayoutTier::route('/{record}/edit'),
        ];
    }
}
