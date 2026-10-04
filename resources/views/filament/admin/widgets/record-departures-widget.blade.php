<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex items-center justify-between gap-4">
            {{-- role="status" so the new count is announced after Record
            departures, same as Active Patrons' copy of this line. --}}
            <p role="status" class="text-sm text-gray-500">
                @if ($this->getCapacity() !== null)
                    {{ __(':occupancy/:capacity in the building', ['occupancy' => $this->getOccupancy(), 'capacity' => $this->getCapacity()]) }}
                @else
                    {{ __(':count in the building', ['count' => $this->getOccupancy()]) }}
                @endif
            </p>
            {{ $this->recordDeparturesAction }}
        </div>
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
