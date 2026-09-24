<x-filament-panels::page>
    <x-screen-instructions :title="__('How to use Active Patrons')">
        <p>{{ __('This lists everyone currently checked in and not yet departed, across every event running right now — not just one event at a time.') }}</p>
        <p>{!! __('Click a member\'s <strong>username</strong> (or the <strong>Depart</strong> button on their row) the moment they leave the building. This immediately frees up capacity for the next walk-in.') !!}</p>
        <p>{!! __('<strong>Visit note</strong> is temporary — use it for something like a clothing description to help spot someone tonight. It\'s not kept after the event.') !!}</p>
        <p>{!! __('<strong>Behavior note</strong> is permanent and goes on the member\'s record — use it for anything staff should know about later, not just tonight.') !!}</p>
        <p>{!! __('Use the <strong>Watchlist only</strong> filter to keep an eye on flagged patrons still in the room.') !!}</p>
        <p>{!! __('Print the <a href=":url" target="_blank" rel="noopener" class="underline">desk reference card</a> for this screen.', ['url' => route('desk-reference-cards').'#active-patrons']) !!}</p>
    </x-screen-instructions>

    <x-filament::section>
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
            {{-- role="status" -- the table below polls every 15s (->poll('15s')),
            which re-renders this whole page component including this count, so it
            changes silently as people arrive/depart from other terminals. --}}
            <p role="status" class="text-sm text-gray-500">
                @if ($this->getCapacity() !== null)
                    {{ __(':occupancy/:capacity in the building', ['occupancy' => $this->getOccupancy(), 'capacity' => $this->getCapacity()]) }}
                @else
                    {{ __(':count in the building', ['count' => $this->getOccupancy()]) }}
                @endif
            </p>

            @if ($this->getActiveEvents()->isNotEmpty())
                <p class="text-sm text-gray-500">
                    {{ __('Active tonight:') }}
                    {{ $this->getActiveEvents()->map(fn ($event) => ($event->name ?? __('Untitled event')).' ('.$event->event_date->translatedFormat('M j, Y').')')->implode(', ') }}
                </p>
            @else
                <p class="text-sm text-gray-500">{{ __('No active events right now.') }}</p>
            @endif
        </div>

        @if ($this->getSignedInStaff()->isNotEmpty())
            {{-- role="status" -- same reasoning as the occupancy count above:
            this re-renders on the table's 15s poll, so it changes silently
            as staff sign in/out elsewhere. --}}
            <p role="status" class="mt-2 text-sm text-gray-500">
                {{ __('Also in the building (signed in):') }}
                {{ $this->getSignedInStaff()->map(fn ($user) => "{$user->name} ({$user->role->displayLabel()})")->implode(', ') }}
            </p>
        @endif
    </x-filament::section>

    {{ $this->getTable()->render() }}
</x-filament-panels::page>
