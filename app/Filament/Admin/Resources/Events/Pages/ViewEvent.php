<?php

namespace App\Filament\Admin\Resources\Events\Pages;

use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Widgets\EventCompCostWidget;
use App\Filament\Admin\Widgets\InstructorPayoutWidget;
use App\Filament\Admin\Widgets\ShowrunnerPayoutWidget;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * The event page for Managers: they can't change an event (EventPolicy::update()
 * is Admin+, since its cost is fixed) but they do run its Attendance, Prepay
 * list and Comp list tabs. The fields are read-only here; the tabs below stay
 * editable (see ReadOnlyWhenEventArchived) unless the event is archived.
 */
class ViewEvent extends ViewRecord
{
    protected static string $resource = EventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // EditAction checks EventPolicy::update(), so only Admins see it.
            EditAction::make(),
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
