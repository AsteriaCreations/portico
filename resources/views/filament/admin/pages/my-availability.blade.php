<x-filament-panels::page>
    <x-screen-instructions :title="__('How to use My availability')">
        <p>{!! __('For each shift of a role you can work, pick <strong>Preferred</strong>, <strong>Available</strong> or <strong>No</strong>, add a note for the week if you need to, then <strong>Save</strong>. Use <strong>Previous week</strong> and <strong>Next week</strong> to move between weeks; save before you move, or your choices for this week are lost.') !!}</p>
        <p>{{ __('You can change your answers until a shift starts. Which roles you can work is set by an Admin.') }}</p>
        @if ($this->canEnterForOthers())
            <p>{!! __('Managers can enter availability for someone who doesn\'t use the app: pick them under <strong>Person</strong>, answer for them, then <strong>Save</strong>. Each answer shows who entered it. Save before switching person.') !!}</p>
        @endif
    </x-screen-instructions>

    @if ($this->canEnterForOthers())
        <x-filament::section>
            {{ $this->personForm }}
        </x-filament::section>
    @endif

    <x-filament::section>
        {{ $this->form }}

        <div class="mt-4">
            {{ $this->saveAction }}
        </div>
    </x-filament::section>
</x-filament-panels::page>
