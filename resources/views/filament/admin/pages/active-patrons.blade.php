<x-filament-panels::page>
    <x-filament::section>
        <div class="flex items-center justify-between gap-4">
            {{-- role="status" -- the table below polls every 15s (->poll('15s')),
            which re-renders this whole page component including this count, so it
            changes silently as people arrive/depart from other terminals. --}}
            <p role="status" class="text-sm text-gray-500">
                @if ($this->getCapacity() !== null)
                    {{ $this->getOccupancy() }}/{{ $this->getCapacity() }} in the building
                @else
                    {{ $this->getOccupancy() }} in the building
                @endif
            </p>

            @if ($this->getActiveEvents()->isNotEmpty())
                <p class="text-sm text-gray-500">
                    Active tonight:
                    {{ $this->getActiveEvents()->map(fn ($event) => ($event->name ?? 'Untitled event').' ('.$event->event_date->toFormattedDateString().')')->implode(', ') }}
                </p>
            @else
                <p class="text-sm text-gray-500">No active events right now.</p>
            @endif
        </div>

        @if ($this->getSignedInStaff()->isNotEmpty())
            {{-- role="status" -- same reasoning as the occupancy count above:
            this re-renders on the table's 15s poll, so it changes silently
            as staff sign in/out elsewhere. --}}
            <p role="status" class="mt-2 text-sm text-gray-500">
                Also in the building (signed in):
                {{ $this->getSignedInStaff()->map(fn ($user) => "{$user->name} ({$user->role->displayLabel()})")->implode(', ') }}
            </p>
        @endif
    </x-filament::section>

    {{ $this->getTable()->render() }}
</x-filament-panels::page>
