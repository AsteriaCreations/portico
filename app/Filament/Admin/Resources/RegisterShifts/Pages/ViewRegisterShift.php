<?php

namespace App\Filament\Admin\Resources\RegisterShifts\Pages;

use App\Filament\Admin\Resources\RegisterShifts\RegisterShiftResource;
use Filament\Resources\Pages\ViewRecord;

// No form/infolist fields of its own -- a shift's own fields are already all
// on RegisterShiftsTable. This page exists purely to host
// MiscellaneousPaymentsRelationManager, since a relation manager needs a
// record-context page and the resource is otherwise list-only (see
// RegisterShiftResource's own note: no create/edit path for anyone).
class ViewRegisterShift extends ViewRecord
{
    protected static string $resource = RegisterShiftResource::class;
}
