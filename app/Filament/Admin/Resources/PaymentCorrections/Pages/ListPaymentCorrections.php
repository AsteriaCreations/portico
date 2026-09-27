<?php

namespace App\Filament\Admin\Resources\PaymentCorrections\Pages;

use App\Filament\Admin\Resources\PaymentCorrections\PaymentCorrectionResource;
use Filament\Resources\Pages\ListRecords;

class ListPaymentCorrections extends ListRecords
{
    protected static string $resource = PaymentCorrectionResource::class;
}
