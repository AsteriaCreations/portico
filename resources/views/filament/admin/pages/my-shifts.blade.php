<x-filament-panels::page>
    <x-screen-instructions :title="__('How to use My shifts')">
        <p>{{ __('The staff schedule once it\'s published: every shift that week and who\'s on it, with yours marked You and listed at the top. Use Previous week and Next week to look at other weeks.') }}</p>
        <p>{{ __('Grid view lays the week out like the paper schedule: Open and Close for each night across the top, a row per role, setup and other shifts on the right, weekday shifts underneath, and who\'s Off or Out. Print prints it.') }}</p>
        <p>{{ __('You get a notification when a week is published, and again if you\'re added to or taken off a shift after that. To say which shifts you can work, use My availability.') }}</p>
    </x-screen-instructions>

    @if ($grid = $this->grid())
        <x-filament::section>
            <x-shift-grid :grid="$grid" :caption="$this->getSubheading()" />
        </x-filament::section>
    @else
        {{ $this->schedule }}
    @endif
</x-filament-panels::page>
