<?php

namespace App\Filament\Admin\Resources\Members\RelationManagers;

use App\Filament\Admin\Resources\PaymentCorrections\Tables\PaymentCorrectionsTable;
use App\Filament\Concerns\TranslatesRelationManagerTitle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/**
 * Read-only -- rows are written only by EntryCorrectionService (see
 * PaymentCorrectionPolicy, which forbids create/update/delete here).
 */
class PaymentCorrectionsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'paymentCorrections';

    protected static ?string $title = 'Payment corrections';

    public function table(Table $table): Table
    {
        return PaymentCorrectionsTable::configure($table);
    }
}
