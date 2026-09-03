<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex items-center justify-between gap-4">
            <p class="text-sm text-gray-500">
                @if ($this->getCapacity() !== null)
                    {{ $this->getOccupancy() }}/{{ $this->getCapacity() }} in the building
                @else
                    {{ $this->getOccupancy() }} in the building
                @endif
            </p>
            {{ $this->recordDeparturesAction }}
        </div>
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
