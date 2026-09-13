<?php

namespace App\Filament\Admin\Resources\Events\Pages;

use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Widgets\EventCompCostWidget;
use App\Filament\Admin\Widgets\InstructorPayoutWidget;
use App\Filament\Admin\Widgets\ShowrunnerPayoutWidget;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEvent extends EditRecord
{
    protected static string $resource = EventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            ShowrunnerPayoutWidget::class,
            InstructorPayoutWidget::class,
            EventCompCostWidget::class,
        ];
    }
}
