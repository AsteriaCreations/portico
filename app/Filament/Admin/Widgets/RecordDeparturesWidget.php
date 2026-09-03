<?php

namespace App\Filament\Admin\Widgets;

use App\Models\OccupancyAdjustment;
use App\Services\CapacityService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Gate;

class RecordDeparturesWidget extends Widget implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected string $view = 'filament.admin.widgets.record-departures-widget';

    // Not lazy: staff need this visible and usable the instant the Dashboard
    // loads, not deferred until it scrolls into view.
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return Gate::allows('record-departures');
    }

    public function getOccupancy(): int
    {
        return app(CapacityService::class)->occupancy(today());
    }

    public function getCapacity(): ?int
    {
        return app(CapacityService::class)->capacity();
    }

    public function recordDeparturesAction(): Action
    {
        return Action::make('recordDepartures')
            ->label('Record departures')
            ->schema([
                TextInput::make('count')
                    ->label('How many people left')
                    ->numeric()
                    ->minValue(1)
                    ->required(),
                Textarea::make('reason')
                    ->maxLength(255),
            ])
            ->action(function (array $data): void {
                OccupancyAdjustment::create([
                    'for_date' => today(),
                    'delta' => -abs((int) $data['count']),
                    'reason' => $data['reason'] ?? null,
                    'recorded_by' => auth()->id(),
                ]);

                Notification::make()->title('Departures recorded')->success()->send();
            });
    }
}
