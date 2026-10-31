<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex items-center justify-between gap-4">
            {{-- role="status" so the new count is announced after Record
            departures, same as Active Patrons' copy of this line. --}}
            <p role="status" class="text-sm text-gray-500">
                @php
                    $inBuilding = $this->getInBuilding();
                    $awaitingArrival = $this->getAwaitingArrival();
                    $capacity = $this->getCapacity();
                @endphp
                {{-- Prepays hold a spot against the cap the moment they're
                added, but aren't in the building until they arrive, so the
                headcount and the spots-taken count part ways until then. --}}
                @if ($awaitingArrival > 0)
                    {{ __(':count in the building', ['count' => $inBuilding]) }}
                    · {{ __(':count prepaid not yet arrived', ['count' => $awaitingArrival]) }}
                    @if ($capacity !== null)
                        · {{ __(':taken/:capacity spots taken', ['taken' => $this->getOccupancy(), 'capacity' => $capacity]) }}
                    @endif
                @elseif ($capacity !== null)
                    {{ __(':occupancy/:capacity in the building', ['occupancy' => $inBuilding, 'capacity' => $capacity]) }}
                @else
                    {{ __(':count in the building', ['count' => $inBuilding]) }}
                @endif
            </p>
            {{ $this->recordDeparturesAction }}
        </div>
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
