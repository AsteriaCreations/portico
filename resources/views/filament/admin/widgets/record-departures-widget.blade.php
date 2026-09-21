<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex items-center justify-between gap-4">
            <p class="text-sm text-gray-500">
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
