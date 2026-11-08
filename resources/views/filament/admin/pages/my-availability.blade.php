<x-filament-panels::page>
    <x-screen-instructions :title="__('How to use My availability')">
        <p>{!! __('For each shift of a role you can work, pick <strong>Preferred</strong>, <strong>Available</strong> or <strong>No</strong>, add a note for the week if you need to, then <strong>Save</strong>. Use <strong>Previous week</strong> and <strong>Next week</strong> to move between weeks; save before you move, or your choices for this week are lost.') !!}</p>
        <p>{{ __('You can change your answers until a shift starts. Which roles you can work is set by an Admin.') }}</p>
    </x-screen-instructions>

    <x-filament::section>
        {{ $this->form }}

        <div class="mt-4">
            {{ $this->saveAction }}
        </div>
    </x-filament::section>
</x-filament-panels::page>
