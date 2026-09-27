<?php

namespace App\Filament\Admin\Resources\PaymentCorrections;

use App\Filament\Admin\Resources\PaymentCorrections\Pages\ListPaymentCorrections;
use App\Filament\Admin\Resources\PaymentCorrections\Tables\PaymentCorrectionsTable;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Models\PaymentCorrection;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

// List only: a correction is written only by the Check-In Desk's "Convert
// entry to subscription" action via EntryCorrectionService, never a generic
// Filament form (see PaymentCorrectionPolicy -- create/update/delete are
// hard-false for everyone).
class PaymentCorrectionResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = PaymentCorrection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Records';

    protected static ?int $navigationSort = 5;

    public static function table(Table $table): Table
    {
        return PaymentCorrectionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentCorrections::route('/'),
        ];
    }
}
