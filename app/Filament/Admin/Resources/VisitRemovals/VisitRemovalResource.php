<?php

namespace App\Filament\Admin\Resources\VisitRemovals;

use App\Filament\Admin\Resources\VisitRemovals\Pages\ListVisitRemovals;
use App\Filament\Admin\Resources\VisitRemovals\Tables\VisitRemovalsTable;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Models\VisitRemoval;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

// List only: a removal is written only by the "Remove" action on an
// attendance tab via VisitRemovalService, never a generic Filament form (see
// VisitRemovalPolicy -- create/update/delete are hard-false for everyone).
class VisitRemovalResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = VisitRemoval::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrash;

    protected static string|UnitEnum|null $navigationGroup = 'Records';

    protected static ?int $navigationSort = 6;

    public static function table(Table $table): Table
    {
        return VisitRemovalsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVisitRemovals::route('/'),
        ];
    }
}
