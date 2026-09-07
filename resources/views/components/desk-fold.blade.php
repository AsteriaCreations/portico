{{-- Plain <details>/<summary>, same as <x-screen-instructions> -- native
    keyboard/screen-reader disclosure with zero JS, collapsed by default.
    Used on the check-in desk to fold occasional-use controls (sell a
    subscription, payment options, cash box) out of the walk-in path a
    volunteer follows every time.

    wire:ignore.self keeps the browser-set `open` attribute across a
    Livewire morph (e.g. after a wire:model.live change inside the fold),
    while still letting the fold's children re-render. --}}
@props(['title', 'summary' => null])

<details
    {{ $attributes->merge(['class' => 'fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10']) }}
    wire:ignore.self
>
    <summary class="cursor-pointer select-none rounded-xl px-6 py-4 text-sm font-medium text-gray-950 dark:text-white">
        {{ $title }}
        @if ($summary)
            <span class="mt-1 block text-xs font-normal text-gray-500 dark:text-gray-400">{{ $summary }}</span>
        @endif
    </summary>

    <div class="space-y-4 px-6 pb-6">
        {{ $slot }}
    </div>
</details>
