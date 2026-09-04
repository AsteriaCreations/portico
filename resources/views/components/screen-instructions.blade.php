{{--
    A collapsed-by-default "how to use this screen" block for staff-facing pages.
    Plain <details>/<summary>, not a Filament schema component -- this is static
    reference text, not something staff fill in or that changes live, so it needs
    none of Schema's form machinery and no role="status" (nothing here re-renders
    on its own). <details> gives free keyboard/screen-reader disclosure semantics
    with zero JS.
--}}
@props(['title' => 'How to use this screen'])

<details class="fi-section mb-6 rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <summary class="cursor-pointer select-none rounded-xl px-6 py-4 text-sm font-medium text-gray-950 dark:text-white">
        {{ $title }}
    </summary>
    <div class="space-y-2 px-6 pb-4 -mt-2 text-sm text-gray-500 dark:text-gray-400">
        {{ $slot }}
    </div>
</details>
